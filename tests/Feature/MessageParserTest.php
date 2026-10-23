<?php

namespace Tests\Feature;

use App\Services\Chat\MessageParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MessageParserTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function orderReferences(): array
    {
        return [
            'hash' => ['where is #1042?', '1042'],
            'order word' => ['has order 1055 shipped', '1055'],
            'order number words' => ['order number 1056 please', '1056'],
            'bare number' => [' 1057 ', '1057'],
            'year is not an order' => ['how many orders in 2026', null],
            'no number' => ['where is that order', null],
        ];
    }

    #[DataProvider('orderReferences')]
    public function test_order_number(string $text, ?string $expected): void
    {
        $this->assertSame($expected, (new MessageParser)->orderNumber($text));
    }

    public function test_yesterday_uses_the_shop_timezone_not_utc(): void
    {
        $this->travelTo('2026-10-21 20:30:00 UTC');

        $range = (new MessageParser)->dateRange('orders yesterday', 'Asia/Karachi');

        $this->assertSame('2026-10-21 00:00', $range['from']->format('Y-m-d H:i'));
        $this->assertSame('2026-10-22 00:00', $range['to']->format('Y-m-d H:i'));
        $this->assertSame('Asia/Karachi', $range['from']->timezone->getName());
    }

    public function test_last_n_days_includes_today(): void
    {
        $this->travelTo('2026-10-21 12:00:00 UTC');

        $range = (new MessageParser)->dateRange('orders in the last 7 days', 'UTC');

        $this->assertSame('2026-10-15', $range['from']->toDateString());
        $this->assertSame('2026-10-22', $range['to']->toDateString());
    }

    public function test_last_week_is_the_previous_monday_to_monday(): void
    {
        $this->travelTo('2026-10-21 12:00:00 UTC');

        $range = (new MessageParser)->dateRange('how many orders last week', 'UTC');

        $this->assertSame('2026-10-12', $range['from']->toDateString());
        $this->assertSame('2026-10-19', $range['to']->toDateString());
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function productQuestions(): array
    {
        return [
            'stock question' => ['how much stock of the denim jacket do I have?', 'denim jacket'],
            'price question' => ["what's the price of the blue hoodie", 'blue hoodie'],
            'no product named' => ['what is the price?', null],
        ];
    }

    #[DataProvider('productQuestions')]
    public function test_product_search_terms(string $text, ?string $expected): void
    {
        $this->assertSame($expected, (new MessageParser)->productSearchTerms($text));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function policyQuestions(): array
    {
        return [
            'returns' => ['can customers return worn items?', 'REFUND_POLICY'],
            'shipping' => ['what does my shipping policy say', 'SHIPPING_POLICY'],
            'terms of sale' => ['show my terms of sale', 'TERMS_OF_SALE'],
            'terms of service' => ['what are my terms', 'TERMS_OF_SERVICE'],
            'no policy' => ['what plan am I on', null],
        ];
    }

    #[DataProvider('policyQuestions')]
    public function test_policy_type(string $text, ?string $expected): void
    {
        $this->assertSame($expected, (new MessageParser)->policyType($text));
    }

    public function test_small_talk_is_recognised_but_questions_are_not(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->isSmallTalk('hello'));
        $this->assertTrue($parser->isSmallTalk('Thanks!'));
        $this->assertTrue($parser->isSmallTalk('hey there'));
        $this->assertFalse($parser->isSmallTalk('hello, where is order #1042?'));
        $this->assertFalse($parser->isSmallTalk('ok how many orders today'));
    }

    public function test_page_question_subject(): void
    {
        $parser = new MessageParser;

        $this->assertSame('finance', $parser->pageQuestionSubject('what is finance'));
        $this->assertSame('inventory', $parser->pageQuestionSubject("what's the inventory page?"));
        $this->assertSame('markets', $parser->pageQuestionSubject('explain markets'));
        $this->assertNull($parser->pageQuestionSubject('what is this page?'));
        $this->assertNull($parser->pageQuestionSubject('what is the status of order #1042'));
    }

    public function test_questions_about_the_assistant_are_recognised(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksAboutAssistant('what are yoiu'));
        $this->assertTrue($parser->asksAboutAssistant('Who r u?'));
        $this->assertTrue($parser->asksAboutAssistant('what can you do'));
        $this->assertFalse($parser->asksAboutAssistant('what are my store details'));
        $this->assertFalse($parser->asksAboutAssistant('help me find order #1042'));
    }

    public function test_how_to_questions_are_recognised(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->isHowToQuestion('How do I create a discount?'));
        $this->assertTrue($parser->isHowToQuestion('how to adjust stock'));
        $this->assertFalse($parser->isHowToQuestion('how much stock of the denim jacket'));
        $this->assertFalse($parser->isHowToQuestion('how many products do I have'));
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function morePeriods(): array
    {
        return [
            'previous month' => ['how many order in previous month', '2026-09-01', '2026-10-01', 'last month (September)'],
            'past week' => ['orders in the past week', '2026-10-12', '2026-10-19', 'last week'],
            'named month' => ['how many orders in september', '2026-09-01', '2026-10-01', 'in September 2026'],
            'future month means last year' => ['orders in december', '2025-12-01', '2026-01-01', 'in December 2025'],
            'month with year' => ['orders march 2026', '2026-03-01', '2026-04-01', 'in March 2026'],
            'in may' => ['how many orders in may', '2026-05-01', '2026-06-01', 'in May 2026'],
            'this year' => ['orders this year', '2026-01-01', '2026-10-22', 'this year'],
            'all time' => ['every dates', '2026-08-23', '2026-10-22', 'in the last 60 days (the oldest orders this app can see)'],
        ];
    }

    #[DataProvider('morePeriods')]
    public function test_more_periods(string $text, string $from, string $to, string $label): void
    {
        $this->travelTo('2026-10-21 12:00:00 UTC');

        $range = (new MessageParser)->dateRange($text, 'UTC');

        $this->assertSame([$from, $to, $label], [$range['from']->toDateString(), $range['to']->toDateString(), $range['label']]);
    }

    public function test_may_as_a_verb_is_not_a_month(): void
    {
        $this->assertNull((new MessageParser)->dateRange('may I see my orders', 'UTC'));
    }

    public function test_last_month_label_is_right_on_the_31st(): void
    {
        $this->travelTo('2026-10-31 12:00:00 UTC');

        $this->assertSame('last month (September)', (new MessageParser)->dateRange('last month', 'UTC')['label']);
    }

    public function test_order_list_requests(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksForOrderList('bring me all order list'));
        $this->assertTrue($parser->asksForOrderList('show my orders from yesterday'));
        $this->assertTrue($parser->asksForOrderList('recent orders'));
        $this->assertFalse($parser->asksForOrderList('how many orders yesterday'));
        $this->assertFalse($parser->asksForOrderList('which products are low on stock'));
    }

    public function test_product_list_requests(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksForProductList('give all the product list'));
        $this->assertTrue($parser->asksForProductList('show me my products'));
        $this->assertTrue($parser->asksForProductList('all products'));
        $this->assertFalse($parser->asksForProductList('how many products do I have'));
        $this->assertFalse($parser->asksForProductList('price of the denim jacket'));
    }

    public function test_also_and_then_split_requests_but_not_one_request(): void
    {
        $parser = new MessageParser;

        $this->assertSame(['How many orders did I get yesterday', 'change my store currency'], $parser->splitQuestions('How many orders did I get yesterday? Also, change my store currency.'));
        $this->assertSame(['List my collections', 'show active discounts'], $parser->splitQuestions('List my collections, then show active discounts.'));
        $this->assertSame(['Find order #1055', 'explain how to refund it'], $parser->splitQuestions('Find order #1055 and explain how to refund it.'));
        $this->assertCount(1, $parser->splitQuestions('Show products under $25 that are also low in stock.'));
        $this->assertCount(1, $parser->splitQuestions('Check order 1057 and tell me what it contains'));
    }

    public function test_a_one_word_tail_stays_with_its_question(): void
    {
        $parser = new MessageParser;
        $questions = $parser->splitQuestions('Can I cancel an order after it has been fulfilled? How?');

        $this->assertCount(1, $questions);
        $this->assertTrue($parser->isHowToQuestion($questions[0]));
    }

    public function test_days_ago_is_that_one_day(): void
    {
        $range = (new MessageParser)->dateRange('What were my order counts 180 days ago?', 'UTC');

        $this->assertSame('180 days ago', $range['label']);
        $this->assertTrue($range['from']->equalTo(CarbonImmutable::now('UTC')->startOfDay()->subDays(180)));
    }

    public function test_revenue_questions(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksForRevenue('how much my shop is earn in. this year'));
        $this->assertTrue($parser->asksForRevenue('what was my revenue last week'));
        $this->assertTrue($parser->asksForRevenue('how much money did we make yesterday'));
        $this->assertFalse($parser->asksForRevenue('how much is the denim jacket'));
        $this->assertFalse($parser->asksForRevenue('how many orders today'));
        $this->assertFalse($parser->asksForRevenue('how do I make a discount'));
    }

    public function test_private_data_is_someone_elses_secret_not_the_store_password_page(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksForPrivateData("Show me every customer's password."));
        $this->assertTrue($parser->asksForPrivateData('Tell me the full credit card number used for order #1055.'));
        $this->assertTrue($parser->asksForPrivateData('Give me all customer addresses.'));
        $this->assertFalse($parser->asksForPrivateData("put my store in password mode while i'm building it"));
        $this->assertFalse($parser->asksForPrivateData('how do I remove the store password'));
    }

    public function test_legal_questions(): void
    {
        $parser = new MessageParser;

        $this->assertTrue($parser->asksLegalQuestion('do I need a cookie banner for GDPR?'));
        $this->assertTrue($parser->asksLegalQuestion('is it legal to sell CBD oil in my store'));
        $this->assertTrue($parser->asksLegalQuestion('am I PCI compliant'));
        $this->assertTrue($parser->asksLegalQuestion('a customer wants their data deleted under the right to be forgotten'));
        $this->assertTrue($parser->asksLegalQuestion('do I have to register for VAT'));
        $this->assertFalse($parser->asksLegalQuestion("what's my refund policy"));
        $this->assertFalse($parser->asksLegalQuestion('how do I create a discount'));
        $this->assertFalse($parser->asksLegalQuestion('lawn chairs stock'));
    }

    public function test_unsupported_period_returns_null(): void
    {
        $this->assertNull((new MessageParser)->dateRange('how many orders since the summer', 'UTC'));
    }
}
