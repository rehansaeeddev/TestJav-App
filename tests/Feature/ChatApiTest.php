<?php

namespace Tests\Feature;

use App\Enums\ChatIntent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Shop;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TOKEN_URL = 'https://shop-a.myshopify.com/admin/oauth/access_token';

    private const GRAPHQL_URL = 'https://shop-a.myshopify.com/admin/api/2026-10/graphql.json';

    private const DECISIONS_URL = 'http://127.0.0.1:8001/v1/systemone';

    /**
     * Fake Shopify's token endpoint and the timezone query, plus the decision engine.
     *
     * @param  string  $scope  Scopes Shopify grants in the token exchange.
     */
    private function fakeShopifyAndEngines(string $scope = 'read_orders,read_products,read_inventory,read_discounts,read_legal_policies'): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'shpat_new_token',
                'scope' => $scope,
                'expires_in' => 3600,
                'refresh_token' => 'shprt_refresh',
                'refresh_token_expires_in' => 7776000,
            ]),
            self::GRAPHQL_URL => Http::response(['data' => ['shop' => ['ianaTimezone' => 'Asia/Karachi']]]),
            self::DECISIONS_URL => Http::response(['answers' => ['answer' => ['choice' => ChatIntent::Other->value]]]),
        ]);
    }

    private function chat(string $token, array $body = ['message' => 'write me a slogan'])
    {
        return $this->withToken($token)->postJson(route('chat'), $body);
    }

    public function test_request_without_token_is_rejected_with_retry_header(): void
    {
        $this->configureShopifyApp();

        $this->postJson(route('chat'), ['message' => 'hi'])
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    }

    public function test_first_request_exchanges_the_session_token_and_stores_the_shop(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();

        $response = $this->chat($this->sessionToken('shop-a.myshopify.com'));

        $response->assertOk()->assertJsonStructure(['data' => ['text', 'admin_link', 'help_url'], 'conversation_id']);

        $shop = Shop::where('domain', 'shop-a.myshopify.com')->sole();
        $this->assertSame('shpat_new_token', $shop->access_token);
        $this->assertSame('Asia/Karachi', $shop->timezone);
        $this->assertTrue($shop->access_token_expires_at->isFuture());
        $this->assertDatabaseMissing('shops', ['access_token' => 'shpat_new_token']);

        Http::assertSent(fn (Request $request) => $request->url() === self::TOKEN_URL
            && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $request['expiring'] === '1');
    }

    public function test_shop_with_a_valid_token_skips_the_exchange(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);

        $this->chat($this->sessionToken('shop-a.myshopify.com'))->assertOk();

        Http::assertNotSent(fn (Request $request) => $request->url() === self::TOKEN_URL);
    }

    public function test_expiring_token_is_renewed_by_exchange(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com', 'access_token_expires_at' => now()->addMinutes(2)]);

        $this->chat($this->sessionToken('shop-a.myshopify.com'))->assertOk();

        $this->assertSame('shpat_new_token', Shop::where('domain', 'shop-a.myshopify.com')->sole()->access_token);
    }

    public function test_token_missing_newly_requested_scopes_is_renewed_once_then_waits(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines(scope: 'read_orders');
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com', 'scopes' => 'read_orders']);
        $token = $this->sessionToken('shop-a.myshopify.com');

        $this->chat($token)->assertOk();
        $this->chat($token)->assertOk();

        Http::assertSentCount(4); // exchange + timezone + decision, then only the second decision
        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->url() === self::TOKEN_URL)->count());
    }

    public function test_missing_scope_fails_and_stores_nothing(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines(scope: 'read_products');

        $this->chat($this->sessionToken('shop-a.myshopify.com'))->assertServerError();

        $this->assertDatabaseMissing('shops', ['domain' => 'shop-a.myshopify.com']);
    }

    public function test_conversation_continues_with_its_id(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $token = $this->sessionToken('shop-a.myshopify.com');

        $conversationId = $this->chat($token)->json('conversation_id');
        $this->chat($token, ['message' => 'hello again', 'conversation_id' => $conversationId])
            ->assertOk()
            ->assertJsonPath('conversation_id', $conversationId);

        $this->assertSame(4, Conversation::findOrFail($conversationId)->messages()->count());
    }

    public function test_another_shop_cannot_read_or_continue_a_conversation(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shopA = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        Shop::factory()->create(['domain' => 'shop-b.myshopify.com']);
        $conversation = Conversation::factory()->for($shopA)->create(['staff_user_id' => '42']);

        $this->chat($this->sessionToken('shop-b.myshopify.com'), ['message' => 'hi', 'conversation_id' => $conversation->id])
            ->assertNotFound();
    }

    public function test_another_staff_member_cannot_continue_a_colleagues_conversation(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $conversation = Conversation::factory()->for($shop)->create(['staff_user_id' => '42']);

        $this->chat($this->sessionToken('shop-a.myshopify.com', ['sub' => '99']), ['message' => 'hi', 'conversation_id' => $conversation->id])
            ->assertNotFound();
    }

    public function test_streamed_reply_sends_model_text_as_it_arrives_then_the_full_reply(): void
    {
        $this->configureShopifyApp();
        Http::preventStrayRequests();
        $chunk = fn (string $text) => 'data: '.json_encode(['choices' => [['delta' => ['content' => $text]]]])."\n\n";
        Http::fake([
            self::DECISIONS_URL => Http::response(['answers' => ['answer' => ['choice' => ChatIntent::Policies->value]]]),
            'http://127.0.0.1:11434/v1/chat/completions' => Http::response(
                $chunk('Unworn items ').$chunk('can be returned.')."data: [DONE]\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);

        $response = $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->withHeader('Accept', 'text/event-stream')
            ->post(route('chat'), ['message' => 'what is my refund policy?']);

        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $events = collect(explode("\n\n", trim($response->streamedContent())))
            ->map(fn (string $line) => json_decode(substr($line, strlen('data: ')), true));

        $this->assertSame(['status', 'status', 'status', 'delta', 'delta', 'done'], $events->pluck('type')->all());
        $this->assertSame(['understanding', 'fetching', 'writing'], $events->take(3)->pluck('stage')->all());
        $this->assertSame('Unworn items ', $events[3]['text']);

        $done = $events->last();
        $this->assertSame('Unworn items can be returned.', $done['data']['text']);
        $this->assertSame('/settings/legal', $done['data']['admin_link']['path']);
        $this->assertSame('Laya → Shopify data → LLM', $done['data']['answered_by']);
        $this->assertIsInt($done['conversation_id']);
        $this->assertSame(Message::query()->where('role', 'assistant')->value('id'), $done['message_id']);
    }

    public function test_staff_can_rate_a_reply_in_their_own_conversation(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $conversation = Conversation::factory()->for($shop)->create(['staff_user_id' => '42']);
        $reply = $conversation->messages()->create(['role' => 'assistant', 'content' => 'Order #1042 ...']);

        $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->postJson(route('messages.feedback', $reply->id), ['rating' => 'wrong'])
            ->assertOk()
            ->assertJsonPath('data.feedback', 'wrong');

        $this->assertSame('wrong', $reply->fresh()->feedback);
    }

    public function test_staff_cannot_rate_a_colleagues_reply_or_their_own_question(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $colleagues = Conversation::factory()->for($shop)->create(['staff_user_id' => '99']);
        $theirReply = $colleagues->messages()->create(['role' => 'assistant', 'content' => 'Hi']);
        $own = Conversation::factory()->for($shop)->create(['staff_user_id' => '42']);
        $ownQuestion = $own->messages()->create(['role' => 'merchant', 'content' => 'hi']);

        $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->postJson(route('messages.feedback', $theirReply->id), ['rating' => 'good'])
            ->assertNotFound();

        $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->postJson(route('messages.feedback', $ownQuestion->id), ['rating' => 'good'])
            ->assertNotFound();

        $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->postJson(route('messages.feedback', $own->messages()->create(['role' => 'assistant', 'content' => 'x'])->id), ['rating' => 'meh'])
            ->assertUnprocessable();
    }

    public function test_latest_conversation_returns_history_with_links(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $conversation = Conversation::factory()->for($shop)->create(['staff_user_id' => '42']);
        $conversation->messages()->create(['role' => 'merchant', 'content' => 'where is order #1042']);
        $conversation->messages()->create([
            'role' => 'assistant', 'content' => 'Order #1042 ...', 'admin_path' => '/orders/5001', 'link_label' => 'Open order #1042',
        ]);

        $this->withToken($this->sessionToken('shop-a.myshopify.com'))
            ->getJson(route('conversations.latest'))
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.messages.0.role', 'merchant')
            ->assertJsonPath('data.messages.1.admin_link', ['label' => 'Open order #1042', 'path' => '/orders/5001']);
    }

    public function test_latest_conversation_never_returns_another_shops_or_colleagues_history(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shopA = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        Shop::factory()->create(['domain' => 'shop-b.myshopify.com']);
        Conversation::factory()->for($shopA)->create(['staff_user_id' => '42']);

        $this->withToken($this->sessionToken('shop-b.myshopify.com'))
            ->getJson(route('conversations.latest'))
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->withToken($this->sessionToken('shop-a.myshopify.com', ['sub' => '99']))
            ->getJson(route('conversations.latest'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_new_chat_starts_an_empty_conversation_that_becomes_the_latest(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        Conversation::factory()->for($shop)->create(['staff_user_id' => '42']);
        $token = $this->sessionToken('shop-a.myshopify.com');

        $newId = $this->withToken($token)->postJson(route('conversations.store'))
            ->assertCreated()
            ->assertJsonPath('data.messages', [])
            ->json('data.id');

        $this->withToken($token)->getJson(route('conversations.latest'))->assertJsonPath('data.id', $newId);
    }

    public function test_message_is_validated(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);

        $this->chat($this->sessionToken('shop-a.myshopify.com'), ['message' => str_repeat('a', 1001)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('message');
    }

    public function test_chat_is_rate_limited_per_staff_member(): void
    {
        $this->configureShopifyApp();
        $this->fakeShopifyAndEngines();
        Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $token = $this->sessionToken('shop-a.myshopify.com');

        for ($i = 0; $i < 20; $i++) {
            $this->chat($token)->assertOk();
        }

        $this->chat($token)->assertTooManyRequests();
    }

    public function test_cors_allows_only_the_configured_frontend(): void
    {
        config(['cors.allowed_origins' => ['https://admin-app.example.com']]);

        $evilResponse = $this->withHeaders(['Origin' => 'https://evil.example.com', 'Access-Control-Request-Method' => 'POST'])
            ->options(route('chat'));

        $this->assertNotSame('https://evil.example.com', $evilResponse->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $evilResponse->headers->get('Access-Control-Allow-Origin'));

        $this->withHeaders(['Origin' => 'https://admin-app.example.com', 'Access-Control-Request-Method' => 'POST'])
            ->options(route('chat'))
            ->assertHeader('Access-Control-Allow-Origin', 'https://admin-app.example.com');
    }

    public function test_signed_uninstall_webhook_deletes_the_shop_and_its_conversations(): void
    {
        $this->configureShopifyApp();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);
        $conversation = Conversation::factory()->for($shop)->create();
        $body = json_encode(['id' => 1, 'domain' => 'shop-a.myshopify.com']);

        $this->call('POST', route('webhooks.app-uninstalled'), server: [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, self::SHOPIFY_SECRET, true)),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'shop-a.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ], content: $body)->assertNoContent();

        $this->assertModelMissing($shop);
        $this->assertModelMissing($conversation);
    }

    public function test_unsigned_uninstall_webhook_is_rejected(): void
    {
        $this->configureShopifyApp();
        $shop = Shop::factory()->create(['domain' => 'shop-a.myshopify.com']);

        $this->call('POST', route('webhooks.app-uninstalled'), server: [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode('forged'),
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'shop-a.myshopify.com',
        ], content: '{}')->assertUnauthorized();

        $this->assertModelExists($shop);
    }
}
