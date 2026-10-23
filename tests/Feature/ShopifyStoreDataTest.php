<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Shopify\OrderFilter;
use App\Services\Shopify\ProductFilter;
use App\Services\Shopify\ShopifyStoreData;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ShopifyStoreDataTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const GRAPHQL_URL = 'https://shop-a.myshopify.com/admin/api/2026-10/graphql.json';

    private function shop(): Shop
    {
        return Shop::factory()->create(['domain' => 'shop-a.myshopify.com', 'timezone' => 'Asia/Karachi']);
    }

    public function test_finds_an_order_and_maps_only_the_fields_the_bot_shows(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['orders' => ['nodes' => [[
            'name' => '#1042',
            'createdAt' => '2026-10-18T09:20:00Z',
            'legacyResourceId' => '5001',
            'displayFinancialStatus' => 'PAID',
            'displayFulfillmentStatus' => 'PARTIALLY_FULFILLED',
            'totalPriceSet' => ['shopMoney' => ['amount' => '79.0', 'currencyCode' => 'PKR']],
            'lineItems' => ['nodes' => [
                ['title' => 'Denim Jacket', 'variantTitle' => 'M', 'quantity' => 2],
                ['title' => 'Gift card', 'variantTitle' => null, 'quantity' => 1],
            ]],
            'fulfillments' => [['trackingInfo' => [['number' => 'TRACK-1']]]],
        ]]]]])]);

        $order = app(ShopifyStoreData::class)->findOrderByNumber($this->shop(), '1042');

        $this->assertSame('1042', $order['number']);
        $this->assertSame('79.00', $order['total']);
        $this->assertSame('2026-10-18 14:20', $order['created_at']->format('Y-m-d H:i'));
        $this->assertSame('partially_fulfilled', $order['fulfillment_status']);
        $this->assertSame(['Denim Jacket (M) ×2', 'Gift card'], $order['items']);
        $this->assertSame('TRACK-1', $order['tracking_number']);
        $this->assertSame('/orders/5001', $order['admin_path']);

        Http::assertSent(fn (Request $request) => $request['variables']['search'] === 'name:#1042'
            && ! str_contains($request['query'], 'email')
            && ! str_contains($request['query'], 'customer'));
    }

    public function test_non_numeric_order_number_never_reaches_shopify(): void
    {
        Http::preventStrayRequests();

        $this->assertNull(app(ShopifyStoreData::class)->findOrderByNumber($this->shop(), '1042 OR name:*'));
    }

    public function test_count_uses_the_shop_day_converted_to_utc(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['ordersCount' => ['count' => 3]]])]);
        $from = CarbonImmutable::parse('2026-10-20 00:00', 'Asia/Karachi');

        $count = app(ShopifyStoreData::class)->countOrders($this->shop(), $from, $from->addDay());

        $this->assertSame(3, $count);
        Http::assertSent(fn (Request $request) => $request['variables']['search']
            === "created_at:>='2026-10-19T19:00:00Z' AND created_at:<'2026-10-20T19:00:00Z'");
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productNode(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Denim Jacket',
            'status' => 'ACTIVE',
            'totalInventory' => 3,
            'tracksInventory' => true,
            'legacyResourceId' => '6001',
            'variants' => ['nodes' => [['title' => 'M', 'price' => '79.00', 'inventoryQuantity' => 3, 'sku' => '']]],
        ], $overrides);
    }

    public function test_find_products_maps_variants_and_strips_search_syntax(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => [
            'shop' => ['currencyCode' => 'PKR'],
            'products' => ['nodes' => [$this->productNode()]],
        ]])]);

        $products = app(ShopifyStoreData::class)->findProducts($this->shop(), 'denim status:draft');

        $this->assertSame([
            'title' => 'Denim Jacket',
            'status' => 'active',
            'total_inventory' => 3,
            'tracks_inventory' => true,
            'currency' => 'PKR',
            'variants' => [['title' => 'M', 'price' => '79.00', 'inventory_quantity' => 3, 'sku' => null]],
            'admin_path' => '/products/6001',
        ], $products[0]);
        Http::assertSent(fn (Request $request) => $request['variables']['search'] === 'denim status draft');
    }

    public function test_lowest_stock_variants_keep_only_active_tracked_products_sorted_by_quantity(): void
    {
        $variant = fn (string $title, ?int $quantity, bool $tracked = true, string $status = 'ACTIVE') => [
            'title' => 'Default Title',
            'inventoryQuantity' => $quantity,
            'product' => ['title' => $title, 'status' => $status, 'tracksInventory' => $tracked, 'legacyResourceId' => '1'],
        ];

        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['productVariants' => ['nodes' => [
            $variant('Tracked twenty', 20),
            $variant('Digital download', -1, tracked: false),
            $variant('Draft board', 0, status: 'DRAFT'),
            $variant('Tracked three', 3),
        ]]]])]);

        $variants = app(ShopifyStoreData::class)->lowestStockVariants($this->shop());

        $this->assertSame(['Tracked three', 'Tracked twenty'], array_column($variants, 'product_title'));
        $this->assertSame([3, 20], array_column($variants, 'quantity'));
        Http::assertSent(fn (Request $request) => str_contains($request['query'], 'managed:true AND product_status:active')
            && str_contains($request['query'], 'sortKey: INVENTORY_QUANTITY'));
    }

    public function test_best_sellers_add_up_pages_and_skip_app_add_ons(): void
    {
        Http::preventStrayRequests();
        $line = fn (string $title, int $quantity, string $amount) => ['title' => $title, 'quantity' => $quantity, 'originalTotalSet' => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']]];
        Http::fake([self::GRAPHQL_URL => Http::sequence()
            ->push(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'],
                'nodes' => [['lineItems' => ['nodes' => [$line('Snow Glasses', 2, '200.00'), $line('Kourify Order Protection', 1, '8.99')]]]],
            ]]])
            ->push(['data' => ['orders' => [
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                'nodes' => [['lineItems' => ['nodes' => [$line('Snow Glasses', 1, '100.00'), $line('Snowboard', 1, '785.95'), $line('Tip', 1, '5.00')]]]],
            ]]]),
        ]);

        $sellers = app(ShopifyStoreData::class)->bestSellers($this->shop(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-22'));

        $this->assertSame([
            ['title' => 'Snow Glasses', 'quantity' => 3, 'revenue' => 300.0, 'currency' => 'USD'],
            ['title' => 'Snowboard', 'quantity' => 1, 'revenue' => 785.95, 'currency' => 'USD'],
        ], $sellers);
        Http::assertSent(fn (Request $request) => ($request['variables']['after'] ?? null) === 'page-2');
    }

    public function test_order_and_product_filters_become_shopify_search(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::sequence()
            ->push(['data' => ['ordersCount' => ['count' => 2]]])
            ->push(['data' => ['shop' => ['currencyCode' => 'USD'], 'products' => ['nodes' => []]]])]);

        $shop = $this->shop();

        app(ShopifyStoreData::class)->countOrders($shop, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-22'),
            new OrderFilter(fulfillment: 'unfulfilled', payment: 'paid', cancelled: false, minTotal: 100.0));
        app(ShopifyStoreData::class)->listProducts($shop, 40, new ProductFilter(stock: 'in', status: 'draft'));

        Http::assertSent(fn (Request $request) => str_ends_with((string) ($request['variables']['search'] ?? ''),
            'fulfillment_status:unfulfilled AND financial_status:paid AND -status:cancelled AND current_total_price:>100'));
        Http::assertSent(fn (Request $request) => ($request['variables']['search'] ?? null) === 'status:draft AND inventory_total:>0');
    }

    public function test_sales_total_adds_order_totals_across_pages(): void
    {
        Http::preventStrayRequests();
        $order = fn (string $amount) => ['currentTotalPriceSet' => ['shopMoney' => ['amount' => $amount, 'currencyCode' => 'USD']]];
        Http::fake([self::GRAPHQL_URL => Http::sequence()
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => true, 'endCursor' => 'page-2'], 'nodes' => [$order('100.50'), $order('20.00')]]]])
            ->push(['data' => ['orders' => ['pageInfo' => ['hasNextPage' => false, 'endCursor' => null], 'nodes' => [$order('9.50')]]]]),
        ]);

        $sales = app(ShopifyStoreData::class)->salesTotal($this->shop(), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-22'));

        $this->assertSame(['total' => 130.0, 'currency' => 'USD', 'orders' => 3, 'complete' => true], $sales);
        Http::assertSent(fn (Request $request) => ($request['variables']['after'] ?? null) === 'page-2');
    }

    public function test_policies_are_plain_text_and_empty_ones_are_dropped(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['shop' => ['shopPolicies' => [
            ['type' => 'REFUND_POLICY', 'title' => 'Refund policy', 'body' => '<p>Returns within <strong>30 days</strong>.</p><p>No gift cards &amp; sale items.</p>', 'url' => 'https://x.test/refund'],
            ['type' => 'PRIVACY_POLICY', 'title' => 'Privacy policy', 'body' => '', 'url' => null],
        ]]]])]);

        $policies = app(ShopifyStoreData::class)->policies($this->shop());

        $this->assertCount(1, $policies);
        $this->assertSame("Returns within 30 days.\nNo gift cards & sale items.", $policies[0]['body']);
    }

    public function test_active_discounts_map_code_and_automatic_discounts(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['data' => ['discountNodes' => ['nodes' => [
            ['discount' => ['title' => 'Welcome', 'summary' => '10% off', 'endsAt' => null, 'codes' => ['nodes' => [['code' => 'WELCOME10']]]]],
            ['discount' => ['title' => 'Free shipping', 'summary' => 'Free shipping over $50', 'endsAt' => '2026-11-01T04:00:00Z']],
            ['discount' => []],
        ]]]])]);

        $discounts = app(ShopifyStoreData::class)->activeDiscounts($this->shop());

        $this->assertCount(2, $discounts);
        $this->assertSame('WELCOME10', $discounts[0]['code']);
        $this->assertNull($discounts[1]['code']);
        $this->assertSame('2026-11-01 09:00', $discounts[1]['ends_at']->format('Y-m-d H:i'));
    }

    public function test_access_denied_keeps_the_token_because_a_new_one_would_not_help(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response([
            'errors' => [['message' => 'Access denied', 'extensions' => ['code' => 'ACCESS_DENIED']]],
        ])]);
        $shop = $this->shop();

        try {
            app(ShopifyStoreData::class)->findOrderByNumber($shop, '1032');
            $this->fail('Expected an exception.');
        } catch (RuntimeException) {
            $this->assertNotNull($shop->fresh()->access_token);
        }
    }

    public function test_graphql_errors_with_http_200_are_raised_with_codes_only(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response([
            'errors' => [['message' => 'Access denied for orders field.', 'extensions' => ['code' => 'ACCESS_DENIED']]],
        ])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shopify Admin API returned errors: ACCESS_DENIED');

        app(ShopifyStoreData::class)->latestOrder($this->shop());
    }

    public function test_rejected_token_is_cleared_so_the_next_request_exchanges_again(): void
    {
        Http::preventStrayRequests();
        Http::fake([self::GRAPHQL_URL => Http::response(['errors' => 'Invalid API key or access token'], 401)]);
        $shop = $this->shop();

        try {
            app(ShopifyStoreData::class)->latestOrder($shop);
            $this->fail('Expected an exception.');
        } catch (RuntimeException) {
            $this->assertNull($shop->fresh()->access_token);
        }
    }
}
