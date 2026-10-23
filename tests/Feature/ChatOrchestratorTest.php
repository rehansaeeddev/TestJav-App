<?php

namespace Tests\Feature;

use App\Enums\ChatIntent;
use App\Models\Conversation;
use App\Models\HelpArticle;
use App\Models\IntentDecision;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Shopify\ShopifyApiError;
use App\Services\Shopify\StoreData;
use Database\Seeders\HelpArticleSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ChatOrchestratorTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const DECISIONS_URL = 'http://127.0.0.1:8001/v1/systemone';

    private const CHAT_URL = 'http://127.0.0.1:11434/v1/chat/completions';

    /**
     * Fake the decision engine (answering with these intents in order) and the chat model.
     * A guide answer (requested with logprobs) starts with "SOURCE: <$guideSource>", as the real model is told to.
     */
    private function fakeEngines(ChatIntent ...$intents): void
    {
        $this->fakeEnginesPickingGuide(1, ...$intents);
    }

    private function fakeEnginesPickingGuide(int $guideSource, ChatIntent ...$intents): void
    {
        Http::preventStrayRequests();

        $decisions = Http::sequence();

        foreach ($intents as $intent) {
            $decisions->push(['answers' => ['answer' => ['type' => 'choice', 'choice' => $intent->value]], 'usage' => []]);
        }

        Http::fake([
            self::DECISIONS_URL => $decisions,
            self::CHAT_URL => fn (Request $request) => Http::response(['choices' => [[
                'message' => ['content' => isset($request['logprobs']) ? "SOURCE: {$guideSource}\nRewritten by the chat model." : 'Rewritten by the chat model.'],
                'logprobs' => ['content' => [['token' => 'SOURCE', 'logprob' => -0.01], ['token' => (string) $guideSource, 'logprob' => -0.2]]],
            ]]]),
        ]);
    }

    private function bot(): ChatOrchestrator
    {
        return app(ChatOrchestrator::class);
    }

    public function test_bot_asks_which_order_and_uses_the_next_message_as_the_answer(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $question = $this->bot()->handle($conversation, 'where is that order');
        $answer = $this->bot()->handle($conversation, '1055');

        $this->assertStringContainsString('Which order do you mean?', $question->text);
        $this->assertStringContainsString('Order #1055', $answer->text);
        $this->assertSame('/orders/5002', $answer->adminPath);
        $this->assertNull($conversation->fresh()->awaiting);
        Http::assertSentCount(1);
    }

    public function test_unknown_order_number_asks_again(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'where is order #9999');

        $this->assertStringContainsString("couldn't find order #9999", $reply->text);
        $this->assertNotNull($conversation->fresh()->awaiting);
    }

    public function test_new_question_while_waiting_for_an_order_number_is_answered_normally(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::FindOrder, ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'where is that order');
        $reply = $this->bot()->handle($conversation, 'how do I adjust stock');

        $this->assertSame('/products/inventory', $reply->adminPath);
        $this->assertNull($conversation->fresh()->awaiting);
    }

    public function test_what_is_this_page_explains_the_page_the_bot_last_sent_the_merchant_to(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo, ChatIntent::ExplainPage);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how do I adjust stock');
        $reply = $this->bot()->handle($conversation, 'what is this page?');

        $this->assertStringStartsWith("If you're on the page I sent you to (Products → Inventory)", $reply->text);
        $this->assertSame('/products/inventory', $reply->adminPath);
    }

    public function test_what_is_this_page_without_memory_asks_which_page_then_explains_it(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::ExplainPage);
        $conversation = Conversation::factory()->create();

        $question = $this->bot()->handle($conversation, 'what is this page?');
        $answer = $this->bot()->handle($conversation, 'the discounts page');

        $this->assertStringContainsString('Which page are you on?', $question->text);
        $this->assertSame('/discounts', $answer->adminPath);
    }

    public function test_explicit_order_number_overrides_a_counting_intent(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'has order 1042 shipped yet');

        $this->assertStringContainsString('Tracking number: 1Z999AA10123456784', $reply->text);
    }

    public function test_order_count_for_yesterday(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how many orders were created yesterday?');

        $this->assertStringStartsWith('3 orders were created yesterday', $reply->text);
    }

    public function test_all_orders_list_counts_everything_visible_and_shows_the_newest(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'bring me all order list');

        $this->assertStringStartsWith('5 orders were created in the last 60 days', $reply->text);
        $this->assertMatchesRegularExpression('/#1058 .*\n• #1057/', $reply->text);
        $this->assertStringContainsString('· paid · fulfilled · 79.00 USD', $reply->text);
        Http::assertNothingSent();
    }

    public function test_recent_orders_without_dates_shows_the_newest_orders(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'recent orders');

        $this->assertStringStartsWith('Your 5 most recent orders:', $reply->text);
    }

    public function test_order_list_for_a_period_shows_the_count_and_orders(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'show me orders from yesterday');

        $this->assertStringStartsWith('3 orders were created yesterday', $reply->text);
        $this->assertStringNotContainsString('#1058', $reply->text);
    }

    public function test_short_unclear_answer_to_the_date_question_asks_again(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how many orders?');
        $reply = $this->bot()->handle($conversation, 'hmm whenever');

        $this->assertStringStartsWith("Sorry, I didn't catch the dates.", $reply->text);
        Http::assertSentCount(1);
    }

    public function test_every_dates_answer_counts_all_visible_orders(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how many orders?');
        $reply = $this->bot()->handle($conversation, 'every dates');

        $this->assertSame('5 orders were created in the last 60 days (the oldest orders this app can see).', $reply->text);
    }

    public function test_period_older_than_the_visible_history_says_so(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how many orders in january 2026');

        $this->assertStringStartsWith("I can't answer that: this app can only see orders from about the last 60 days", $reply->text);
        $this->assertStringNotContainsString('0 orders', $reply->text);
    }

    public function test_period_partly_older_than_the_visible_history_counts_what_it_can_and_says_so(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how many orders in the last 90 days');

        $this->assertStringContainsString("I can only see orders from about the last 60 days, so older orders aren't included.", $reply->text);
    }

    public function test_order_numbers_and_counts_never_go_through_the_chat_model(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder, ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'where is order #1042');
        $this->bot()->handle($conversation, 'how many orders today');

        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_falls_back_to_help_search_when_the_decision_engine_is_down(): void
    {
        $this->seed(HelpArticleSeeder::class);
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::DECISIONS_URL => Http::response([], 503),
            self::CHAT_URL => Http::response(['choices' => [['message' => ['content' => 'Rewritten.']]]]),
        ]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do I refund a customer');

        $this->assertSame('/orders', $reply->adminPath);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_how_to_is_answered_by_the_llm_from_the_guide_it_picked(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do I adjust stock');

        $this->assertSame('Rewritten by the chat model.', $reply->text);
        $this->assertSame('guide:adjust-inventory', $reply->target);
        $this->assertSame('Laya → RAG → LLM', $reply->answeredBy);
        $this->assertEqualsWithDelta(0.82, $reply->confidence['llm'], 0.01);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL && str_contains($request['messages'][0]['content'], 'GUIDE 1: Adjust stock'));
    }

    public function test_how_to_shows_the_closest_guide_as_written_when_the_llm_is_down(): void
    {
        $this->seed(HelpArticleSeeder::class);
        Exceptions::fake();
        Http::fake([
            self::DECISIONS_URL => Http::response(['answers' => ['answer' => ['type' => 'choice', 'choice' => ChatIntent::HowTo->value]], 'usage' => []]),
            self::CHAT_URL => Http::response('down', 503),
        ]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do I adjust stock');

        $this->assertStringStartsWith("Adjust stock (inventory) quantities:\n1. In your Shopify admin, go to Products → Inventory.", $reply->text);
    }

    public function test_shows_the_policy_text_when_the_chat_model_is_down(): void
    {
        Exceptions::fake();
        Http::preventStrayRequests();
        Http::fake([
            self::DECISIONS_URL => Http::response(['answers' => ['answer' => ['choice' => ChatIntent::Policies->value]]]),
            self::CHAT_URL => Http::response([], 500),
        ]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what is my refund policy');

        $this->assertStringStartsWith('Unworn items can be returned within 30 days', $reply->text);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_shopify_access_denied_gives_the_merchant_a_clear_message(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        Exceptions::fake();
        $this->mock(StoreData::class)
            ->shouldReceive('findOrderByNumber')
            ->andThrow(new ShopifyApiError('Shopify Admin API returned errors: ACCESS_DENIED', ['ACCESS_DENIED']));
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'where is order #1032');

        $this->assertStringContainsString("doesn't have permission to read that data", $reply->text);
        $this->assertStringNotContainsString('ACCESS_DENIED', $reply->text);
        Exceptions::assertReported(ShopifyApiError::class);
    }

    public function test_product_lookup_shows_price_and_stock_per_variant(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how much stock of the denim jacket do I have?');

        $this->assertStringContainsString('Denim Jacket (active)', $reply->text);
        $this->assertStringContainsString('• Total stock: 8 in stock', $reply->text);
        $this->assertStringContainsString('• S: 79.00 USD, out of stock', $reply->text);
        $this->assertStringContainsString('• M: 79.00 USD, 3 in stock', $reply->text);
        $this->assertSame('/products/6001', $reply->adminPath);
    }

    public function test_bot_asks_which_product_when_none_is_named(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $question = $this->bot()->handle($conversation, "what's the price?");
        $answer = $this->bot()->handle($conversation, 'blue hoodie');

        $this->assertStringContainsString('Which product?', $question->text);
        $this->assertStringContainsString('Blue Hoodie (active)', $answer->text);
    }

    public function test_page_named_before_the_question_is_explained_with_its_tasks(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::ExplainPage);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'Campaign Autopilot what is this page what task i can perfome there');

        $this->assertStringStartsWith('Campaign Autopilot runs your marketing', $reply->text);
        $this->assertStringContainsString("What you can do there:\n1. In your Shopify admin, go to Growth → Autopilot.", $reply->text);
    }

    public function test_unknown_page_says_there_is_no_guide_instead_of_guessing(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEnginesPickingGuide(0, ChatIntent::ExplainPage);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what is the zebra dashboard page');

        $this->assertStringStartsWith("I'm not sure I have the right guide for that.", $reply->text);
        $this->assertSame('https://help.shopify.com/en/search?q=zebra%20dashboard%20page', $reply->helpUrl);
    }

    public function test_follow_up_moves_to_another_guide_only_when_keyword_and_meaning_agree(): void
    {
        $this->seed(HelpArticleSeeder::class);
        HelpArticle::where('slug', 'draft-order-collect-payment')->update(['embedding' => json_encode([1.0, 0.0])]);
        HelpArticle::where('slug', 'create-order')->update(['embedding' => json_encode([0.0, 1.0])]);
        $this->fakeEngines(ChatIntent::HowTo);
        Http::fake(['http://127.0.0.1:11434/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])]);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how to create order');
        $reply = $this->bot()->handle($conversation, 'there is the option here saying collect payment / card payment');

        $this->assertSame('guide:draft-order-collect-payment', $reply->target);
        $this->assertSame('https://help.shopify.com/en/manual/fulfillment/managing-orders/create-orders/get-paid', $reply->helpUrl);
    }

    public function test_follow_up_without_a_clear_new_guide_stays_on_the_guide_shown(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how to create order');
        $reply = $this->bot()->handle($conversation, 'Can you explain the second step?');

        $this->assertSame('guide:create-order', $reply->target);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && str_contains($request['messages'][0]['content'], 'GUIDE: Create an order for a customer'));
    }

    public function test_how_to_about_this_order_uses_the_order_shown_before(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::FindOrder, ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'show me order #1042');
        $reply = $this->bot()->handle($conversation, 'can you tell me how i can cancel this order');

        $this->assertSame('guide:cancel-order', $reply->target);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && str_contains($request['messages'][0]['content'], 'Order #1042')
            && str_contains($request['messages'][0]['content'], 'Fulfillment: fulfilled'));
    }

    public function test_is_it_paid_answers_about_the_order_shown_before(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'show me order #1056');
        $reply = $this->bot()->handle($conversation, 'is it paid yet?');

        $this->assertSame('order:1056', $reply->target);
        $this->assertSame('order_from_memory', IntentDecision::query()->latest('id')->value('decided_by'));
    }

    public function test_their_prices_uses_the_products_shown_before(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'which products are low on stock');
        $reply = $this->bot()->handle($conversation, 'what about their prices?');

        $this->assertStringContainsString('Denim Jacket (active)', $reply->text);
        $this->assertStringContainsString('Blue Hoodie (active)', $reply->text);
        $this->assertStringContainsString('USD', $reply->text);
    }

    public function test_a_new_chat_has_no_memory_of_the_old_one(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder, ChatIntent::FindOrder);
        $old = Conversation::factory()->create();
        $this->bot()->handle($old, 'show me order #1042');

        $new = Conversation::factory()->for($old->shop)->create(['staff_user_id' => $old->staff_user_id]);
        $reply = $this->bot()->handle($new, 'is it paid?');

        $this->assertSame('ask:OrderNumber', $reply->target);
    }

    /**
     * Laya answers $intent with $confidence; the chat model answers guide prompts with "SOURCE: $source".
     */
    private function fakeUnsureLaya(ChatIntent $intent, float $confidence, string $source): void
    {
        Http::preventStrayRequests();
        Http::fake([
            self::DECISIONS_URL => Http::response(['answers' => ['answer' => ['type' => 'choice', 'choice' => $intent->value, 'confidence' => $confidence]], 'usage' => []]),
            self::CHAT_URL => fn (Request $request) => Http::response(['choices' => [[
                'message' => ['content' => isset($request['logprobs']) ? "SOURCE: {$source}\nAnswered from the guide." : 'Rewritten by the chat model.'],
                'logprobs' => ['content' => [['token' => $source, 'logprob' => -0.1]]],
            ]]]),
        ]);
    }

    public function test_unsure_laya_without_a_data_signal_goes_to_rag_and_llm(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeUnsureLaya(ChatIntent::FindOrder, 0.33, '1');
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'I need a new order for a phone customer');

        $this->assertSame('Answered from the guide.', $reply->text);
        $this->assertSame('laya > laya_unsure_rag_llm', IntentDecision::query()->value('decided_by'));
        $this->assertSame('Laya → RAG → LLM', $reply->answeredBy);
    }

    public function test_unsure_laya_gives_way_to_a_clue_in_the_question(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeUnsureLaya(ChatIntent::OrderStats, 0.30, '1');
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what email do customers see');

        $this->assertStringContainsString('Your store details', $reply->text);
        $this->assertDatabaseHas('intent_decisions', ['decided_by' => 'laya > unsure_laya_signal', 'final_intent' => ChatIntent::StoreDetails->value]);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_unsure_laya_on_products_answers_data_unless_a_task_is_named(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeUnsureLaya(ChatIntent::Products, 0.30, '1');
        $conversation = Conversation::factory()->create();

        $this->assertSame('product:Beanie', $this->bot()->handle($conversation, 'beanie stock?')->target);
        $this->assertSame('product:Leather Belt', $this->bot()->handle($conversation, 'is the leather belt live on my store')->target);

        HelpArticle::where('slug', 'archive-product')->update(['embedding' => json_encode([1.0, 0.0])]);
        Http::fake(['http://127.0.0.1:11434/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])]);
        $this->assertSame('guide:archive-product', $this->bot()->handle(Conversation::factory()->create(), 'temporarily hide a product without deleting it')->target);
    }

    public function test_a_task_is_never_answered_with_store_data_even_if_the_model_says_so(): void
    {
        $this->seed(HelpArticleSeeder::class);
        HelpArticle::where('slug', 'archive-product')->update(['embedding' => json_encode([1.0, 0.0])]);
        $this->fakeUnsureLaya(ChatIntent::Products, 0.30, 'D');
        Http::fake(['http://127.0.0.1:11434/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])]);

        $reply = $this->bot()->handle(Conversation::factory()->create(), 'temporarily hide a product without deleting it');

        $this->assertSame('guide:archive-product', $reply->target);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL && ! str_contains($request['messages'][0]['content'], 'SOURCE: D'));
    }

    public function test_unfulfilled_list_shows_only_unfulfilled_orders_and_a_correction_keeps_the_list(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats, ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'and you show me the list of all the unfulfilled orders');

        $this->assertStringContainsString('3 unfulfilled orders were created', $reply->text);
        $this->assertStringContainsString('#1055', $reply->text);
        $this->assertStringNotContainsString('#1042', $reply->text);
        $this->assertStringNotContainsString('#1057', $reply->text);

        $this->bot()->handle($conversation, 'show me my orders');
        $correction = $this->bot()->handle($conversation, 'but i told you that i want unfulfilled');

        $this->assertStringContainsString('unfulfilled orders', $correction->text);
        $this->assertStringContainsString('#1058', $correction->text);
        $this->assertStringNotContainsString('#1042', $correction->text);
    }

    public function test_order_filters_for_payment_amount_and_size(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats, ChatIntent::OrderStats, ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->assertStringStartsWith('1 unpaid order was created', $this->bot()->handle($conversation, 'how many unpaid orders do i have')->text);
        $this->assertStringStartsWith('1 order over 100 was created', $this->bot()->handle($conversation, 'how many orders over $100')->text);

        $biggest = $this->bot()->handle($conversation, 'what is my biggest order');
        $this->assertMatchesRegularExpression('/biggest orders:\n• #1057/', $biggest->text);
    }

    public function test_product_filters_for_price_status_and_stock(): void
    {
        $this->fakeEngines(ChatIntent::Products, ChatIntent::Products, ChatIntent::Products, ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $under50 = $this->bot()->handle($conversation, 'show products under $50');
        $this->assertStringStartsWith('3 products under 50:', $under50->text);
        $this->assertStringNotContainsString('Denim Jacket', $under50->text);

        $this->assertStringStartsWith('1 draft product:', $this->bot()->handle($conversation, 'list my draft products')->text);
        $this->assertStringStartsWith('You have no products under 25, low in stock.', $this->bot()->handle($conversation, 'Show products under $25 that are also low in stock.')->text);

        $fewer = $this->bot()->handle($conversation, 'which products have fewer than three units left');
        $this->assertStringContainsString('Blue Hoodie', $fewer->text);
        $this->assertStringNotContainsString('Beanie', $fewer->text);
    }

    public function test_export_wishes_are_how_to_questions(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'i want all my orders in excel');

        $this->assertDatabaseHas('intent_decisions', ['decided_by' => 'laya > how_to_phrasing', 'final_intent' => ChatIntent::HowTo->value]);
    }

    public function test_unsure_laya_keeps_a_question_that_clearly_asks_for_store_data(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeUnsureLaya(ChatIntent::FindOrder, 0.10, '1');
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'did 1056 ship yet');

        $this->assertSame('order:1056', $reply->target);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_unsure_laya_uses_store_data_when_the_llm_says_so(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeUnsureLaya(ChatIntent::Products, 0.27, 'D');
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how much is the denim jacket');

        $this->assertSame('product:Denim Jacket', $reply->target);
        $this->assertSame('laya > laya_unsure_llm_says_store_data', IntentDecision::query()->value('decided_by'));
    }

    public function test_store_details_never_show_the_private_store_email(): void
    {
        $this->fakeEngines(ChatIntent::StoreDetails);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what are my store details');

        $this->assertStringNotContainsString('owner@demo-shop.test', $reply->text);
        $this->assertStringContainsString('Customer contact email: support@demo-shop.test', $reply->text);
    }

    public function test_best_sellers_are_counted_from_real_orders(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what are my best sellers?');

        $this->assertSame(
            "Your best sellers in the last 30 days:\n1. Denim Jacket: 3 sold, 237.00 USD\n2. Blue Hoodie: 3 sold, 135.00 USD\n3. Beanie: 1 sold, 15.00 USD",
            $reply->text,
        );
        $this->assertSame('best_sellers', IntentDecision::query()->value('decided_by'));
        $this->assertSame(['Denim Jacket', 'Blue Hoodie', 'Beanie'], $conversation->fresh()->context['products']);
    }

    public function test_best_sellers_for_a_period(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'top selling products today');

        $this->assertSame('Your best sellers today ('.now()->format('M j')."):\n1. Blue Hoodie: 1 sold, 45.00 USD", $reply->text);
    }

    public function test_growth_advice_is_decided_from_the_stores_own_numbers_without_the_llm(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how to increased my sales');

        $this->assertSame('growth_advice', IntentDecision::query()->value('decided_by'));
        $this->assertSame('Rule → Shopify data', $reply->answeredBy);
        $this->assertStringContainsString('1. **Restock Blue Hoodie**: it sold 3 in the last 30 days and only 2 are left (about 20 days).', $reply->text);
        $this->assertStringContainsString('**Restock or hide sold-out sizes**: 2 tracked variants are at 0', $reply->text);
        $this->assertStringContainsString('**Publish your 1 draft product**', $reply->text);
        $this->assertStringContainsString('half your orders are under 79.00 USD; free shipping over about 100.00 USD', $reply->text);
        $this->assertSame($reply->text, $this->bot()->handle($conversation, 'how to increased my sales')->text);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_which_products_to_buy_ranks_best_sellers_by_days_of_stock_left(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'which product i should buy for my inventory');

        $this->assertStringContainsString("• Blue Hoodie: 3 sold, 2 left, about 20 days of stock → reorder now\n• Denim Jacket: 3 sold, 8 left, about 80 days of stock", $reply->text);
        $this->assertSame('restock', $reply->target);
        Http::assertNothingSent();
    }

    public function test_requests_to_change_the_store_are_explained_not_attempted(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'can you delete all my order');

        $this->assertSame('action_request', IntentDecision::query()->value('decided_by'));
        $this->assertStringNotContainsString('orders were created', $reply->text);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && str_contains($request['messages'][0]['content'], 'You cannot change anything in their store'));
    }

    public function test_each_question_logs_how_it_was_routed(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::Policies);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'show me my last order and which currency do I use');

        $decisions = IntentDecision::query()->orderBy('part')->get();

        $this->assertCount(2, $decisions);
        $this->assertSame('latest_order', $decisions[0]->decided_by);
        $this->assertNull($decisions[0]->laya_choice);
        $this->assertSame('laya > no_policy_word', $decisions[1]->decided_by);
        $this->assertSame(ChatIntent::Policies, $decisions[1]->laya_choice);
        $this->assertSame(ChatIntent::StoreDetails, $decisions[1]->final_intent);
    }

    public function test_a_failed_laya_call_is_logged_as_a_failure_not_as_a_decision(): void
    {
        Http::fake([self::DECISIONS_URL => Http::response('down', 503)]);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'which discounts are active');

        $decision = IntentDecision::sole();
        $this->assertSame('laya_failed', $decision->decided_by);
        $this->assertNull($decision->laya_choice);
        $this->assertStringContainsString('503', $decision->error);
    }

    public function test_when_laya_is_down_the_question_clues_still_route_it(): void
    {
        Http::fake([self::DECISIONS_URL => Http::response('down', 503)]);
        $conversation = Conversation::factory()->create();

        $this->assertStringContainsString('orders were created this week', $this->bot()->handle($conversation, 'How many orders arrived this week?')->text);
        $this->assertStringContainsString('Your store details', $this->bot()->handle($conversation, "Tell me my store's name, currency, and timezone.")->text);
    }

    public function test_two_questions_in_one_message_get_two_answers(): void
    {
        $this->fakeEngines(ChatIntent::Products, ChatIntent::DiscountsAndCollections);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how many products do I have and which discounts are active?');

        $this->assertStringStartsWith("You have 4 products: 3 active, 1 draft.\n\nActive discounts:", $reply->text);
        $this->assertSame(2, $conversation->messages()->count());
    }

    public function test_merchant_can_change_topic_right_after_a_guide(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo, ChatIntent::Policies);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how to create order');
        $reply = $this->bot()->handle($conversation, 'ok then what is my refund policy');

        $this->assertSame('Rewritten by the chat model.', $reply->text);
        $this->assertSame('/settings/legal', $reply->adminPath);
    }

    public function test_new_how_to_right_after_a_guide_gets_its_own_guide(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo, ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how to create order');
        $reply = $this->bot()->handle($conversation, 'how do I cancel an order then');

        $this->assertSame('guide:cancel-order', $reply->target);
    }

    public function test_how_to_without_a_clear_guide_says_so_instead_of_guessing(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do I speed up the checkout page');

        $this->assertStringStartsWith("I'm not sure I have the right guide for that.", $reply->text);
        $this->assertNull($reply->adminPath);
    }

    public function test_discounts_need_a_discount_word(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::DiscountsAndCollections);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'card payment option');

        $this->assertStringNotContainsString('Active discounts', $reply->text);
    }

    public function test_insult_gets_an_apology_not_a_product_question(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'damn you idiot');

        $this->assertStringStartsWith('Sorry, I got that wrong.', $reply->text);
        $this->assertNull($conversation->fresh()->awaiting);
    }

    public function test_a_reply_that_is_not_a_product_name_is_answered_as_a_new_question(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $question = $this->bot()->handle($conversation, 'stock of the red scarf');
        $answer = $this->bot()->handle($conversation, 'give all the product list');

        $this->assertStringContainsString("I couldn't find a product", $question->text);
        $this->assertStringStartsWith('Your 4 products:', $answer->text);
        $this->assertNull($conversation->fresh()->awaiting);
    }

    public function test_low_stock_lists_low_variants_of_active_tracked_products(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'which products are low on stock?');

        $this->assertStringStartsWith('5 variants have 5 or fewer in stock:', $reply->text);
        $this->assertStringContainsString('• Denim Jacket (S): out of stock', $reply->text);
        $this->assertStringContainsString('• Blue Hoodie (L): 2 left', $reply->text);
        $this->assertStringNotContainsString('Beanie', $reply->text);
        $this->assertStringNotContainsString('Leather Belt', $reply->text);
        $this->assertSame('/products/inventory', $reply->adminPath);
    }

    public function test_when_nothing_is_low_the_lowest_stock_is_still_shown(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $this->mock(StoreData::class)->shouldReceive('lowestStockVariants')->andReturn([
            ['product_title' => 'The Complete Snowboard', 'variant_title' => 'Electric', 'quantity' => 10, 'admin_path' => '/products/1'],
            ['product_title' => 'The Draft Snowboard', 'variant_title' => 'Default Title', 'quantity' => 20, 'admin_path' => '/products/2'],
            ['product_title' => 'The 3p Fulfilled Snowboard', 'variant_title' => 'Default Title', 'quantity' => 20, 'admin_path' => '/products/3'],
            ['product_title' => 'Snow Glasses', 'variant_title' => 'Default Title', 'quantity' => 79, 'admin_path' => '/products/4'],
        ]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'which products are low on stock?');

        $this->assertStringStartsWith('Nothing is low: no tracked product has 5 or fewer in stock.', $reply->text);
        $this->assertStringContainsString('• The Complete Snowboard (Electric): 10 left', $reply->text);
        $this->assertStringContainsString('• The Draft Snowboard: 20 left', $reply->text);
        $this->assertStringNotContainsString('Snow Glasses', $reply->text);
        $this->assertStringContainsString("don't track inventory", $reply->text);
    }

    public function test_store_without_tracked_inventory_gets_an_explanation(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $this->mock(StoreData::class)->shouldReceive('lowestStockVariants')->andReturn([]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, "what's out of stock?");

        $this->assertStringContainsString('Track quantity', $reply->text);
    }

    public function test_product_count_by_status(): void
    {
        $this->fakeEngines(ChatIntent::Products);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how many products do I have');

        $this->assertSame('You have 4 products: 3 active, 1 draft.', $reply->text);
    }

    public function test_product_list_shows_all_products_even_when_the_engine_picks_something_else(): void
    {
        $this->fakeEngines(ChatIntent::Other);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'give all the product list');

        $this->assertSame(
            "Your 4 products:\n• Beanie: 12 in stock\n• Blue Hoodie: 2 in stock\n• Denim Jacket: 8 in stock\n• Leather Belt (draft): out of stock\n"
            .'Send me a product name to see its prices and stock per variant.',
            $reply->text,
        );
        $this->assertSame('/products', $reply->adminPath);
    }

    public function test_how_do_i_questions_go_to_the_guides_even_when_the_engine_picks_store_data(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::DiscountsAndCollections);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do I create a discount');

        $this->assertSame('Open Discounts → Create discount', $reply->linkLabel);
        $this->assertSame(ChatIntent::HowTo, $conversation->messages()->latest('id')->first()->intent);
    }

    public function test_policy_answer_is_written_only_from_the_store_policy_text(): void
    {
        $this->fakeEngines(ChatIntent::Policies);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what is my refund policy?');

        $this->assertSame('Rewritten by the chat model.', $reply->text);
        $this->assertSame('/settings/legal', $reply->adminPath);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && str_contains($request['messages'][0]['content'], 'returned within 30 days'));
    }

    public function test_missing_policy_says_it_is_not_set_up(): void
    {
        $this->fakeEngines(ChatIntent::Policies);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what does my privacy policy say');

        $this->assertStringContainsString("doesn't have a privacy policy set up yet", $reply->text);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_policy_pick_without_a_policy_word_answers_with_store_details(): void
    {
        $this->fakeEngines(ChatIntent::Policies);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what plan am I on');

        $this->assertStringContainsString('• Plan: Development', $reply->text);
        $this->assertSame('/settings/general', $reply->adminPath);
    }

    public function test_active_discounts_and_collections(): void
    {
        $this->fakeEngines(ChatIntent::DiscountsAndCollections, ChatIntent::DiscountsAndCollections);
        $conversation = Conversation::factory()->create();

        $discounts = $this->bot()->handle($conversation, 'which discounts are active?');
        $collections = $this->bot()->handle($conversation, 'what collections do I have');

        $this->assertStringContainsString('• Welcome 10% (code WELCOME10): 10% off entire order', $discounts->text);
        $this->assertStringContainsString('(automatic)', $discounts->text);
        $this->assertStringContainsString('• Winter: 3 products', $collections->text);
    }

    public function test_reply_links_are_saved_with_the_message_for_history(): void
    {
        $this->fakeEngines(ChatIntent::FindOrder);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'where is order #1042');

        $message = $conversation->messages()->latest('id')->first();
        $this->assertSame('/orders/5001', $message->admin_path);
        $this->assertSame('Open order #1042', $message->link_label);
    }

    public function test_your_eight_live_questions_get_sensible_answers(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();
        $ask = fn (string $text) => $this->bot()->handle($conversation, $text);

        $this->assertStringStartsWith("I'm doing well", $ask('hey how are you')->text);
        $this->assertStringStartsWith("I'm doing well", $ask('i said how are you')->text);
        $this->assertStringStartsWith("I can't see your personal details", $ask('whats my name')->text);
        $this->assertStringStartsWith('Sorry, I got that wrong.', $ask('why cant you tell me the right answer')->text);
        $this->assertStringStartsWith('Sorry, I got that wrong.', $ask('are you insan')->text);
        $this->assertStringContainsString("I can't change anything", $ask('can you manage my store')->text);
        $this->assertSame('guide:content-section', $ask('what is content page')->target);
        $this->assertSame('guide:content-section', $ask('can you explain in simple wording')->target);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::DECISIONS_URL);
    }

    public function test_greetings_skip_the_decision_engine_and_get_a_greeting(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $this->assertStringStartsWith('Hi! What can I help you with', $this->bot()->handle($conversation, 'hello')->text);
        $this->assertStringStartsWith("You're welcome!", $this->bot()->handle($conversation, 'thanks!')->text);
        Http::assertNothingSent();
    }

    public function test_this_setting_with_nothing_shown_yet_asks_which_one(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'How do I change this setting?');

        $this->assertSame('ask:SettingName', $reply->target);
        Http::assertNothingSent();
    }

    public function test_what_is_a_named_admin_page_explains_that_page_without_asking_the_engine(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what is finance');

        $this->assertStringContainsString('Shopify Balance', $reply->text);
        $this->assertSame('https://help.shopify.com/en/manual/finance', $reply->helpUrl);
        Http::assertNothingSent();
    }

    public function test_what_is_the_inventory_page_links_to_inventory(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, "what's the inventory page?");

        $this->assertSame('/products/inventory', $reply->adminPath);
    }

    public function test_store_details_are_not_a_catch_all(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::StoreDetails);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what is the weather');

        $this->assertStringStartsWith("I'm your store assistant.", $reply->text);
        $this->assertStringNotContainsString('Your store details', $reply->text);
    }

    public function test_bare_order_number_with_a_status_word_looks_up_that_order(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'did 1056 ship yet');

        $this->assertStringContainsString('1056', $reply->text);
        $this->assertSame('order:1056', $reply->target);
        Http::assertNothingSent();
    }

    public function test_store_setting_question_laya_calls_off_topic_gets_store_details(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::Other);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what timezone is my store set to');

        $this->assertStringContainsString('Your store details', $reply->text);
        $this->assertDatabaseHas('intent_decisions', ['laya_choice' => ChatIntent::Other->value, 'final_intent' => ChatIntent::StoreDetails->value]);
    }

    public function test_how_much_did_i_earn_is_a_money_total_not_an_order_count(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how much my shop is earn in. this year');

        $this->assertStringStartsWith('Sales this year: 391.00 USD from 5 orders', $reply->text);
        $this->assertSame('revenue', $reply->target);
        Http::assertNothingSent();
    }

    public function test_earnings_without_a_period_use_the_last_30_days(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how much money did we make');

        $this->assertStringStartsWith('Sales in the last 30 days: 391.00 USD', $reply->text);
    }

    public function test_the_day_before_moves_the_last_period_back_and_keeps_the_measure(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats, ChatIntent::OrderStats, ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();
        $day = fn (int $daysAgo) => now('UTC')->startOfDay()->subDays($daysAgo)->format('M j');

        $this->assertStringStartsWith('3 orders were created yesterday', $this->bot()->handle($conversation, 'How many orders arrived yesterday?')->text);
        $this->assertStringStartsWith('0 orders were created on '.$day(2), $this->bot()->handle($conversation, 'What about the day before?')->text);
        $this->assertStringStartsWith('1 order was created on '.$day(3), $this->bot()->handle($conversation, 'and the day before that?')->text);
    }

    public function test_follow_up_period_keeps_asking_about_sales(): void
    {
        $this->fakeEngines(ChatIntent::OrderStats, ChatIntent::OrderStats);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'how much did i earn yesterday');
        $reply = $this->bot()->handle($conversation, 'what about the day before?');

        $this->assertStringStartsWith('No sales on '.now('UTC')->startOfDay()->subDays(2)->format('M j'), $reply->text);
        $this->assertSame('revenue', $reply->target);
    }

    public function test_when_search_is_sure_the_model_writes_from_that_one_guide_without_choosing(): void
    {
        $this->seed(HelpArticleSeeder::class);
        HelpArticle::where('slug', 'create-discount')->update(['embedding' => json_encode([1.0, 0.0])]);
        HelpArticle::where('slug', 'refund-order')->update(['embedding' => json_encode([0.0, 1.0])]);
        $this->fakeEngines(ChatIntent::HowTo);
        Http::fake(['http://127.0.0.1:11434/v1/embeddings' => Http::response(['data' => [['index' => 0, 'embedding' => [1.0, 0.0]]]])]);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'how do i set up a promo for my shop');

        $this->assertSame('guide:create-discount', $reply->target);
        Http::assertSent(fn (Request $request) => $request->url() === self::CHAT_URL
            && str_contains($request['messages'][0]['content'], 'Answer using ONLY the guide below.')
            && ! str_contains($request['messages'][0]['content'], 'SOURCE')
            && ! isset($request['logprobs']));
    }

    public function test_private_data_is_refused_without_asking_laya(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, "Show me every customer's password.");

        $this->assertStringStartsWith("I can't share passwords", $reply->text);
        Http::assertNothingSent();
    }

    public function test_future_orders_are_not_counted(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'Tell me exactly how many orders will arrive tomorrow.');

        $this->assertStringStartsWith("I can't predict future orders", $reply->text);
        $this->assertSame('future', $reply->target);
    }

    public function test_short_follow_up_stays_on_the_last_store_topic(): void
    {
        $this->fakeEngines(ChatIntent::StoreDetails, ChatIntent::Other);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, "What's my store currency?");
        $reply = $this->bot()->handle($conversation, 'And my timezone?');

        $this->assertStringContainsString('Your store details', $reply->text);
        $this->assertDatabaseHas('intent_decisions', ['decided_by' => 'topic_follow_up', 'final_intent' => ChatIntent::StoreDetails->value]);
    }

    public function test_requests_to_change_the_store_always_start_by_saying_it_cannot(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'Restock the blue hoodie by 50 units.');

        $this->assertStringStartsWith(ChatOrchestrator::CANT_CHANGE_STORE, $reply->text);
        $this->assertSame(1, substr_count($reply->text, ChatOrchestrator::CANT_CHANGE_STORE));
    }

    public function test_page_named_anywhere_in_a_page_question_is_explained(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'What is the purpose of the Discounts page?');

        $this->assertDatabaseHas('intent_decisions', ['decided_by' => 'named_page', 'target' => 'guide:create-discount']);
    }

    public function test_legal_questions_are_answered_from_guides_with_a_fixed_disclaimer(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'do I need a cookie banner for GDPR?');

        $this->assertStringEndsWith(ChatOrchestrator::LEGAL_DISCLAIMER, $reply->text);
        $this->assertStringStartsWith('guide:', $reply->target);
        $this->assertDatabaseHas('intent_decisions', ['decided_by' => 'legal_question', 'final_intent' => ChatIntent::HowTo->value]);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::DECISIONS_URL);
    }

    public function test_streamed_legal_answer_also_ends_with_the_disclaimer(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();
        $streamed = '';

        $this->bot()->handle($conversation, 'is a privacy policy required by law', function (string $delta) use (&$streamed) {
            $streamed .= $delta;
        });

        $this->assertStringEndsWith(ChatOrchestrator::LEGAL_DISCLAIMER, $streamed);
    }

    public function test_questions_about_the_assistant_get_an_introduction_not_store_data(): void
    {
        $this->fakeEngines();
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'what are yoiu');

        $this->assertStringStartsWith("I'm your store assistant.", $reply->text);
        $this->assertNull($reply->adminPath);
        Http::assertNothingSent();
    }

    public function test_unrelated_request_gets_no_random_guide_or_page_memory(): void
    {
        $this->seed(HelpArticleSeeder::class);
        $this->fakeEngines(ChatIntent::HowTo);
        $conversation = Conversation::factory()->create();

        $reply = $this->bot()->handle($conversation, 'can you help me hack the shopify');

        $this->assertStringStartsWith("I don't have a guide for that yet.", $reply->text);
        $this->assertNull($reply->adminPath);
        $this->assertNull($conversation->fresh()->last_help_article_id);
        Http::assertNotSent(fn (Request $request) => $request->url() === self::CHAT_URL);
    }

    public function test_every_exchange_is_saved_with_its_intent(): void
    {
        $this->fakeEngines(ChatIntent::Other);
        $conversation = Conversation::factory()->create();

        $this->bot()->handle($conversation, 'write me a slogan');

        Http::assertSentCount(1);
        $this->assertSame(
            [['merchant', null], ['assistant', ChatIntent::Other->value]],
            $conversation->messages()->orderBy('id')->get()
                ->map(fn ($message) => [$message->role->value, $message->intent?->value])->all(),
        );
    }
}
