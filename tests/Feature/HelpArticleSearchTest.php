<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\HelpArticle;
use App\Models\Shop;
use Database\Seeders\HelpArticleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HelpArticleSearchTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function merchantQuestions(): array
    {
        return [
            'create order' => ['how can I create an order', 'create-order'],
            'adjust stock' => ['how do i adjust stock', 'adjust-inventory'],
            'tracking number' => ['where do I put a tracking number', 'fulfill-order'],
            'refund' => ['give money back to customer', 'refund-order'],
            'coupon' => ['coupon code', 'create-discount'],
        ];
    }

    #[DataProvider('merchantQuestions')]
    public function test_search_returns_the_matching_article_first(string $question, string $expectedSlug): void
    {
        $this->seed(HelpArticleSeeder::class);

        $this->assertSame($expectedSlug, HelpArticle::search($question)->value('slug'));
    }

    public function test_fts_operators_in_user_text_are_treated_as_plain_words(): void
    {
        $this->seed(HelpArticleSeeder::class);

        $results = HelpArticle::search('NEAR("order" *) -- DROP help_articles')->pluck('slug');

        $this->assertContains('find-order', $results);
    }

    public function test_generic_words_alone_do_not_match_a_guide(): void
    {
        $this->seed(HelpArticleSeeder::class);

        $this->assertSame(0, HelpArticle::search('can you help me hack the shopify')->count());
    }

    public function test_text_with_only_stop_words_returns_nothing(): void
    {
        $this->seed(HelpArticleSeeder::class);

        $this->assertSame(0, HelpArticle::search('how do I do this?')->count());
    }

    public function test_search_index_follows_updates_and_deletes(): void
    {
        $article = HelpArticle::factory()->create(['keywords' => 'zebra']);

        $article->update(['keywords' => 'giraffe']);

        $this->assertSame(0, HelpArticle::search('zebra')->count());
        $this->assertSame($article->id, HelpArticle::search('giraffe')->value('id'));

        $article->delete();

        $this->assertSame(0, HelpArticle::search('giraffe')->count());
    }

    public function test_shop_tokens_are_encrypted_at_rest_and_hidden_from_serialization(): void
    {
        $shop = Shop::factory()->create(['access_token' => 'shpat_secret_value']);

        $this->assertDatabaseMissing('shops', ['access_token' => 'shpat_secret_value']);
        $this->assertSame('shpat_secret_value', $shop->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $shop->toArray());
    }

    public function test_deleting_a_shop_deletes_its_conversations(): void
    {
        $conversation = Conversation::factory()->create();

        $conversation->shop->delete();

        $this->assertModelMissing($conversation);
    }
}
