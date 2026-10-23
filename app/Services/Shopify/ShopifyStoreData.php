<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Real store data from the GraphQL Admin API. Only reads order fields the bot shows:
 * no customer names, emails, addresses or phone numbers are requested.
 * Note: the read_orders scope only covers roughly the last 60 days of orders.
 */
class ShopifyStoreData implements StoreData
{
    private const ORDER_FIELDS = <<<'GRAPHQL'
        name
        createdAt
        legacyResourceId
        displayFinancialStatus
        displayFulfillmentStatus
        totalPriceSet { shopMoney { amount currencyCode } }
        lineItems(first: 10) { nodes { title variantTitle quantity } }
        fulfillments(first: 5) { trackingInfo { number } }
        GRAPHQL;

    private const PRODUCT_FIELDS = 'title status totalInventory tracksInventory legacyResourceId variants(first: 20) { nodes { title price inventoryQuantity sku } }';

    /** Line items apps add to orders (shipping protection, tips, gift wrap): not products the store sells. */
    private const ADD_ON_TITLES = '/\b(protection|insurance|shipping|tips?|gift\s*wrap(?:ping)?)\b/i';

    public function __construct(private AdminApi $api) {}

    public function findOrderByNumber(Shop $shop, string $orderNumber): ?array
    {
        $orderNumber = ltrim($orderNumber, '#');

        if (! ctype_digit($orderNumber)) {
            return null;
        }

        $data = $this->api->query($shop,
            'query ($search: String!) { orders(first: 1, query: $search) { nodes { '.self::ORDER_FIELDS.' } } }',
            ['search' => "name:#{$orderNumber}"],
        );

        $node = $data['orders']['nodes'][0] ?? null;

        return $node === null ? null : $this->toOrder($shop, $node);
    }

    public function latestOrder(Shop $shop): ?array
    {
        $data = $this->api->query($shop,
            'query { orders(first: 1, sortKey: CREATED_AT, reverse: true) { nodes { '.self::ORDER_FIELDS.' } } }',
        );

        $node = $data['orders']['nodes'][0] ?? null;

        return $node === null ? null : $this->toOrder($shop, $node);
    }

    public function countOrders(Shop $shop, CarbonInterface $from, CarbonInterface $to, ?OrderFilter $filter = null): int
    {
        $data = $this->api->query($shop,
            'query ($search: String!) { ordersCount(query: $search, limit: null) { count } }',
            ['search' => $this->withFilter($this->createdBetween($from, $to), $filter)],
        );

        return (int) ($data['ordersCount']['count'] ?? 0);
    }

    public function salesTotal(Shop $shop, CarbonInterface $from, CarbonInterface $to): array
    {
        $total = 0.0;
        $orders = 0;
        $currency = '';
        $cursor = null;

        /** Up to 10 pages of 250: one total field per order keeps each page cheap. */
        for ($page = 0; $page < 10; $page++) {
            $data = $this->api->query($shop,
                'query ($search: String!, $after: String) { orders(first: 250, after: $after, query: $search) {
                    pageInfo { hasNextPage endCursor }
                    nodes { currentTotalPriceSet { shopMoney { amount currencyCode } } }
                } }',
                ['search' => $this->createdBetween($from, $to), 'after' => $cursor],
            );

            foreach ($data['orders']['nodes'] ?? [] as $order) {
                $total += (float) ($order['currentTotalPriceSet']['shopMoney']['amount'] ?? 0);
                $currency = (string) ($order['currentTotalPriceSet']['shopMoney']['currencyCode'] ?? $currency);
                $orders++;
            }

            if (! ($data['orders']['pageInfo']['hasNextPage'] ?? false)) {
                return ['total' => $total, 'currency' => $currency, 'orders' => $orders, 'complete' => true];
            }

            $cursor = $data['orders']['pageInfo']['endCursor'];
        }

        return ['total' => $total, 'currency' => $currency, 'orders' => $orders, 'complete' => false];
    }

    public function recentOrders(Shop $shop, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 10, ?OrderFilter $filter = null): array
    {
        $data = $this->api->query($shop,
            'query ($search: String, $limit: Int!, $sortKey: OrderSortKeys!, $reverse: Boolean!) { orders(first: $limit, query: $search, sortKey: $sortKey, reverse: $reverse) { nodes { '.self::ORDER_FIELDS.' } } }',
            [
                'search' => $this->withFilter($from !== null && $to !== null ? $this->createdBetween($from, $to) : null, $filter),
                'limit' => $limit,
                'sortKey' => $filter?->sort !== null ? 'CURRENT_TOTAL_PRICE' : 'CREATED_AT',
                'reverse' => $filter?->sort !== 'smallest',
            ],
        );

        return collect($data['orders']['nodes'] ?? [])
            ->map(fn (array $node) => $this->toOrder($shop, $node))
            ->all();
    }

    /**
     * Adds the merchant's filter ("unfulfilled", "over $100") to an order search.
     */
    private function withFilter(?string $search, ?OrderFilter $filter): ?string
    {
        $parts = array_filter([$search, $filter?->query()]);

        return $parts === [] ? null : implode(' AND ', $parts);
    }

    /**
     * Shopify search filter for orders created in [from, to), in UTC.
     */
    private function createdBetween(CarbonInterface $from, CarbonInterface $to): string
    {
        return sprintf("created_at:>='%s' AND created_at:<'%s'",
            $from->toImmutable()->utc()->toIso8601ZuluString(),
            $to->toImmutable()->utc()->toIso8601ZuluString(),
        );
    }

    public function findProducts(Shop $shop, string $search, int $limit = 5): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $search, $matches);

        if ($matches[0] === []) {
            return [];
        }

        $data = $this->api->query($shop,
            'query ($search: String!, $limit: Int!) { shop { currencyCode } products(first: $limit, query: $search, sortKey: RELEVANCE) { nodes { '.self::PRODUCT_FIELDS.' } } }',
            ['search' => implode(' ', $matches[0]), 'limit' => $limit],
        );

        return $this->toProducts($data);
    }

    public function listProducts(Shop $shop, int $limit = 20, ?ProductFilter $filter = null): array
    {
        $data = $this->api->query($shop,
            'query ($limit: Int!, $search: String) { shop { currencyCode } products(first: $limit, query: $search, sortKey: TITLE) { nodes { '.self::PRODUCT_FIELDS.' } } }',
            ['limit' => $limit, 'search' => $filter?->query()],
        );

        return $this->toProducts($data);
    }

    /**
     * Shopify ignores an inventory_quantity filter here (checked on a real store), but honours
     * "managed" and the stock sort, so the lowest tracked variants come first. Product status
     * and tracking are checked again in PHP rather than trusting the search filter alone.
     */
    public function lowestStockVariants(Shop $shop, int $limit = 50): array
    {
        $data = $this->api->query($shop,
            'query ($limit: Int!) { productVariants(first: $limit, query: "managed:true AND product_status:active", sortKey: INVENTORY_QUANTITY) {
                nodes { title inventoryQuantity product { title status tracksInventory legacyResourceId } }
            } }',
            ['limit' => $limit],
        );

        return collect($data['productVariants']['nodes'] ?? [])
            ->filter(fn (array $variant) => ($variant['product']['tracksInventory'] ?? false)
                && ($variant['product']['status'] ?? null) === 'ACTIVE'
                && $variant['inventoryQuantity'] !== null)
            ->map(fn (array $variant) => [
                'product_title' => (string) $variant['product']['title'],
                'variant_title' => (string) $variant['title'],
                'quantity' => (int) $variant['inventoryQuantity'],
                'admin_path' => '/products/'.$variant['product']['legacyResourceId'],
            ])
            ->sortBy('quantity')
            ->values()
            ->all();
    }

    public function countProducts(Shop $shop, ?string $status = null): int
    {
        $data = $this->api->query($shop,
            'query ($search: String) { productsCount(query: $search, limit: null) { count } }',
            ['search' => $status === null ? null : 'status:'.strtolower($status)],
        );

        return (int) ($data['productsCount']['count'] ?? 0);
    }

    public function storeDetails(Shop $shop): array
    {
        $data = $this->api->query($shop, 'query { shop {
            name email contactEmail currencyCode ianaTimezone myshopifyDomain
            primaryDomain { url }
            plan { publicDisplayName }
            billingAddress { country }
        } }')['shop'] ?? [];

        return [
            'name' => (string) ($data['name'] ?? ''),
            'email' => $data['email'] ?? null,
            'contact_email' => $data['contactEmail'] ?? null,
            'currency' => (string) ($data['currencyCode'] ?? ''),
            'timezone' => (string) ($data['ianaTimezone'] ?? 'UTC'),
            'domain' => $data['primaryDomain']['url'] ?? null,
            'myshopify_domain' => (string) ($data['myshopifyDomain'] ?? $shop->domain),
            'plan' => $data['plan']['publicDisplayName'] ?? null,
            'country' => $data['billingAddress']['country'] ?? null,
        ];
    }

    public function policies(Shop $shop): array
    {
        $policies = $this->api->query($shop, 'query { shop { shopPolicies { type title body url } } }')['shop']['shopPolicies'] ?? [];

        return collect($policies)
            ->map(fn (array $policy) => [
                'type' => (string) $policy['type'],
                'title' => (string) $policy['title'],
                'body' => $this->htmlToText((string) ($policy['body'] ?? '')),
                'url' => $policy['url'] ?? null,
            ])
            ->filter(fn (array $policy) => $policy['body'] !== '')
            ->values()
            ->all();
    }

    /**
     * Pages through orders 50 at a time: one query asking for every order's line items at once
     * goes over Shopify's per-query cost limit.
     */
    public function bestSellers(Shop $shop, CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $totals = [];
        $cursor = null;

        for ($page = 0; $page < 5; $page++) {
            $data = $this->api->query($shop,
                'query ($search: String!, $after: String) { orders(first: 50, after: $after, query: $search) {
                    pageInfo { hasNextPage endCursor }
                    nodes { lineItems(first: 15) { nodes { title quantity originalTotalSet { shopMoney { amount currencyCode } } } } }
                } }',
                ['search' => $this->createdBetween($from, $to), 'after' => $cursor],
            );

            foreach ($data['orders']['nodes'] ?? [] as $order) {
                foreach ($order['lineItems']['nodes'] ?? [] as $item) {
                    $title = (string) $item['title'];

                    /** Add-ons that apps put on orders (shipping protection, tips) aren't products the store sells. */
                    if (preg_match(self::ADD_ON_TITLES, $title)) {
                        continue;
                    }

                    $totals[$title] ??= ['title' => $title, 'quantity' => 0, 'revenue' => 0.0, 'currency' => (string) ($item['originalTotalSet']['shopMoney']['currencyCode'] ?? '')];
                    $totals[$title]['quantity'] += (int) $item['quantity'];
                    $totals[$title]['revenue'] += (float) ($item['originalTotalSet']['shopMoney']['amount'] ?? 0);
                }
            }

            if (! ($data['orders']['pageInfo']['hasNextPage'] ?? false)) {
                break;
            }

            $cursor = $data['orders']['pageInfo']['endCursor'];
        }

        return collect($totals)
            ->sortBy([['quantity', 'desc'], ['revenue', 'desc']])
            ->take($limit)
            ->values()
            ->all();
    }

    public function activeDiscounts(Shop $shop, int $limit = 20): array
    {
        $codeFields = 'title summary endsAt codes(first: 1) { nodes { code } }';
        $automaticFields = 'title summary endsAt';

        $data = $this->api->query($shop, 'query ($limit: Int!) { discountNodes(first: $limit, query: "status:active") { nodes { discount {
            ... on DiscountCodeBasic { '.$codeFields.' }
            ... on DiscountCodeBxgy { '.$codeFields.' }
            ... on DiscountCodeFreeShipping { '.$codeFields.' }
            ... on DiscountCodeApp { title endsAt codes(first: 1) { nodes { code } } }
            ... on DiscountAutomaticBasic { '.$automaticFields.' }
            ... on DiscountAutomaticBxgy { '.$automaticFields.' }
            ... on DiscountAutomaticFreeShipping { '.$automaticFields.' }
            ... on DiscountAutomaticApp { title endsAt }
        } } } }', ['limit' => $limit]);

        return collect($data['discountNodes']['nodes'] ?? [])
            ->pluck('discount')
            ->filter(fn ($discount) => filled($discount['title'] ?? null))
            ->map(fn (array $discount) => [
                'title' => (string) $discount['title'],
                'summary' => $discount['summary'] ?? null,
                'code' => $discount['codes']['nodes'][0]['code'] ?? null,
                'ends_at' => filled($discount['endsAt'] ?? null)
                    ? CarbonImmutable::parse($discount['endsAt'])->setTimezone($shop->timezone ?? 'UTC')
                    : null,
            ])
            ->values()
            ->all();
    }

    public function collections(Shop $shop, int $limit = 20): array
    {
        $data = $this->api->query($shop,
            'query ($limit: Int!) { collections(first: $limit, sortKey: TITLE) { nodes { title legacyResourceId productsCount { count } } } }',
            ['limit' => $limit],
        );

        return collect($data['collections']['nodes'] ?? [])
            ->map(fn (array $collection) => [
                'title' => (string) $collection['title'],
                'products_count' => (int) ($collection['productsCount']['count'] ?? 0),
                'admin_path' => '/collections/'.$collection['legacyResourceId'],
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{title: string, status: string, total_inventory: int, tracks_inventory: bool, currency: string, variants: list<array{title: string, price: string, inventory_quantity: int|null, sku: string|null}>, admin_path: string}>
     */
    private function toProducts(array $data): array
    {
        $currency = (string) ($data['shop']['currencyCode'] ?? '');

        return collect($data['products']['nodes'] ?? [])
            ->map(fn (array $node) => [
                'title' => (string) $node['title'],
                'status' => strtolower((string) $node['status']),
                'total_inventory' => (int) ($node['totalInventory'] ?? 0),
                'tracks_inventory' => (bool) ($node['tracksInventory'] ?? false),
                'currency' => $currency,
                'variants' => collect($node['variants']['nodes'] ?? [])
                    ->map(fn (array $variant) => [
                        'title' => (string) $variant['title'],
                        'price' => $this->money($variant['price'] ?? 0),
                        'inventory_quantity' => $variant['inventoryQuantity'] ?? null,
                        'sku' => filled($variant['sku'] ?? null) ? $variant['sku'] : null,
                    ])
                    ->all(),
                'admin_path' => '/products/'.$node['legacyResourceId'],
            ])
            ->all();
    }

    /**
     * Shopify sends amounts like "129.0"; show them as "129.00".
     */
    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Policy bodies are HTML; the bot and the chat model work with plain text.
     */
    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<\s*(br|\/p|\/li|\/h\d|\/div)\s*\/?>/i', "\n", $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/[ \t]+/", ' ', preg_replace("/\n\s*\n+/", "\n", $text)));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array{number: string, created_at: CarbonImmutable, financial_status: string, fulfillment_status: string, total: string, currency: string, items: list<string>, tracking_number: string|null, admin_path: string}
     */
    private function toOrder(Shop $shop, array $node): array
    {
        $items = collect($node['lineItems']['nodes'] ?? [])
            ->map(function (array $item): string {
                $label = $item['title'].(filled($item['variantTitle'] ?? null) ? " ({$item['variantTitle']})" : '');

                return ($item['quantity'] ?? 1) > 1 ? "{$label} ×{$item['quantity']}" : $label;
            })
            ->values()
            ->all();

        $trackingNumber = collect($node['fulfillments'] ?? [])
            ->flatMap(fn (array $fulfillment) => $fulfillment['trackingInfo'] ?? [])
            ->pluck('number')
            ->filter()
            ->first();

        return [
            'number' => ltrim((string) $node['name'], '#'),
            'created_at' => CarbonImmutable::parse($node['createdAt'])->setTimezone($shop->timezone ?? 'UTC'),
            'financial_status' => strtolower((string) ($node['displayFinancialStatus'] ?? 'unknown')),
            'fulfillment_status' => strtolower((string) ($node['displayFulfillmentStatus'] ?? 'unknown')),
            'total' => $this->money($node['totalPriceSet']['shopMoney']['amount'] ?? 0),
            'currency' => (string) ($node['totalPriceSet']['shopMoney']['currencyCode'] ?? ''),
            'items' => $items,
            'tracking_number' => $trackingNumber,
            'admin_path' => '/orders/'.$node['legacyResourceId'],
        ];
    }
}
