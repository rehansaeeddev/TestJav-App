<?php

namespace Tests\Fakes;

use App\Models\Shop;
use App\Services\Shopify\OrderFilter;
use App\Services\Shopify\ProductFilter;
use App\Services\Shopify\StoreData;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A made-up store for tests and the accuracy measurements (chat:eval): its answers never change,
 * unlike a real store. Lives in tests/ so the live app can never use it.
 * Dates are relative to "now" in the shop's timezone, so "yesterday" always has orders.
 * No customer names, emails or addresses: the bot doesn't need them.
 */
class FakeStoreData implements StoreData
{
    public function findOrderByNumber(Shop $shop, string $orderNumber): ?array
    {
        return collect($this->orders($shop))->firstWhere('number', ltrim($orderNumber, '#'));
    }

    public function latestOrder(Shop $shop): ?array
    {
        return collect($this->orders($shop))->sortByDesc('created_at')->first();
    }

    public function countOrders(Shop $shop, CarbonInterface $from, CarbonInterface $to, ?OrderFilter $filter = null): int
    {
        return collect($this->orders($shop))
            ->filter(fn (array $order) => $order['created_at'] >= $from && $order['created_at'] < $to && $this->matches($order, $filter))
            ->count();
    }

    public function salesTotal(Shop $shop, CarbonInterface $from, CarbonInterface $to): array
    {
        $orders = collect($this->orders($shop))
            ->filter(fn (array $order) => $order['created_at'] >= $from && $order['created_at'] < $to);

        return ['total' => (float) $orders->sum(fn (array $order) => (float) $order['total']), 'currency' => 'USD', 'orders' => $orders->count(), 'complete' => true];
    }

    public function recentOrders(Shop $shop, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 10, ?OrderFilter $filter = null): array
    {
        return collect($this->orders($shop))
            ->filter(fn (array $order) => ($from === null || $order['created_at'] >= $from) && ($to === null || $order['created_at'] < $to) && $this->matches($order, $filter))
            ->sortBy(fn (array $order) => match ($filter?->sort) {
                'largest' => -(float) $order['total'],
                'smallest' => (float) $order['total'],
                default => -$order['created_at']->getTimestamp(),
            })
            ->take($limit)
            ->values()
            ->all();
    }

    public function findProducts(Shop $shop, string $search, int $limit = 5): array
    {
        $words = explode(' ', mb_strtolower($search));

        return collect($this->products())
            ->filter(fn (array $product) => collect($words)->every(fn (string $word) => str_contains(mb_strtolower($product['title']), $word)))
            ->take($limit)
            ->values()
            ->all();
    }

    public function listProducts(Shop $shop, int $limit = 20, ?ProductFilter $filter = null): array
    {
        return collect($this->products())
            ->filter(fn (array $product) => ($filter?->status === null || $product['status'] === $filter->status)
                && match ($filter?->stock) {
                    'out' => $product['total_inventory'] === 0,
                    'in' => $product['total_inventory'] > 0,
                    default => true,
                })
            ->sortBy('title')
            ->take($limit)
            ->values()
            ->all();
    }

    public function lowestStockVariants(Shop $shop, int $limit = 50): array
    {
        return collect($this->products())
            ->filter(fn (array $product) => $product['status'] === 'active' && $product['tracks_inventory'])
            ->flatMap(fn (array $product) => collect($product['variants'])->map(fn (array $variant) => [
                'product_title' => $product['title'],
                'variant_title' => $variant['title'],
                'quantity' => (int) $variant['inventory_quantity'],
                'admin_path' => $product['admin_path'],
            ]))
            ->sortBy('quantity')
            ->take($limit)
            ->values()
            ->all();
    }

    public function countProducts(Shop $shop, ?string $status = null): int
    {
        return collect($this->products())
            ->filter(fn (array $product) => $status === null || $product['status'] === strtolower($status))
            ->count();
    }

    public function storeDetails(Shop $shop): array
    {
        return [
            'name' => 'Demo Shop',
            'email' => 'owner@demo-shop.test',
            'contact_email' => 'support@demo-shop.test',
            'currency' => 'USD',
            'timezone' => $shop->timezone ?? 'UTC',
            'domain' => 'https://demo-shop.test',
            'myshopify_domain' => $shop->domain,
            'plan' => 'Development',
            'country' => 'United States',
        ];
    }

    public function policies(Shop $shop): array
    {
        return [
            [
                'type' => 'REFUND_POLICY',
                'title' => 'Refund policy',
                'body' => 'Unworn items can be returned within 30 days for a full refund. Worn items and gift cards cannot be returned. Refunds go back to the original payment method within 5 business days.',
                'url' => 'https://demo-shop.test/policies/refund-policy',
            ],
            [
                'type' => 'SHIPPING_POLICY',
                'title' => 'Shipping policy',
                'body' => 'Orders ship within 2 business days. Shipping is free over $50, otherwise $5.99.',
                'url' => 'https://demo-shop.test/policies/shipping-policy',
            ],
        ];
    }

    public function bestSellers(Shop $shop, CarbonInterface $from, CarbonInterface $to, int $limit = 5): array
    {
        $prices = collect($this->products())->mapWithKeys(fn (array $product) => [$product['title'] => (float) $product['variants'][0]['price']]);

        return collect($this->orders($shop))
            ->filter(fn (array $order) => $order['created_at']->greaterThanOrEqualTo($from) && $order['created_at']->lessThan($to))
            ->flatMap(fn (array $order) => $order['items'])
            ->map(fn (string $item) => trim((string) preg_replace('/\s*\(.*\)$/', '', $item)))
            ->countBy()
            ->map(fn (int $quantity, string $title) => ['title' => $title, 'quantity' => $quantity, 'revenue' => $quantity * ($prices[$title] ?? 0.0), 'currency' => 'USD'])
            ->sortBy([['quantity', 'desc'], ['revenue', 'desc']])
            ->take($limit)
            ->values()
            ->all();
    }

    public function activeDiscounts(Shop $shop, int $limit = 20): array
    {
        return [
            ['title' => 'Welcome 10%', 'summary' => '10% off entire order', 'code' => 'WELCOME10', 'ends_at' => null],
            ['title' => 'Free shipping over $50', 'summary' => 'Free shipping on orders over $50.00', 'code' => null, 'ends_at' => CarbonImmutable::now()->addDays(10)->startOfDay()],
        ];
    }

    public function collections(Shop $shop, int $limit = 20): array
    {
        return [
            ['title' => 'Accessories', 'products_count' => 2, 'admin_path' => '/collections/7001'],
            ['title' => 'Winter', 'products_count' => 3, 'admin_path' => '/collections/7002'],
        ];
    }

    /**
     * The sample orders have no discount codes, cancellations or fraud risk, so those filters match none.
     *
     * @param  array<string, mixed>  $order
     */
    private function matches(array $order, ?OrderFilter $filter): bool
    {
        if ($filter === null) {
            return true;
        }

        $fulfillment = ['partial' => 'partially_fulfilled'][$filter->fulfillment] ?? $filter->fulfillment;

        return ($fulfillment === null || $order['fulfillment_status'] === $fulfillment)
            && ($filter->payment === null || $order['financial_status'] === $filter->payment)
            && $filter->cancelled !== true
            && ($filter->minTotal === null || (float) $order['total'] > $filter->minTotal)
            && ($filter->maxTotal === null || (float) $order['total'] < $filter->maxTotal)
            && $filter->discountCode === null
            && ! $filter->highRisk;
    }

    /**
     * @return list<array{title: string, status: string, total_inventory: int, tracks_inventory: bool, currency: string, variants: list<array{title: string, price: string, inventory_quantity: int|null, sku: string|null}>, admin_path: string}>
     */
    private function products(): array
    {
        return [
            $this->product('Denim Jacket', 'active', [['S', '79.00', 0], ['M', '79.00', 3], ['L', '79.00', 5]], 6001),
            $this->product('Blue Hoodie', 'active', [['M', '45.00', 0], ['L', '45.00', 2]], 6002),
            $this->product('Beanie', 'active', [['Default Title', '15.00', 12]], 6003),
            $this->product('Leather Belt', 'draft', [['Default Title', '35.00', 0]], 6004),
        ];
    }

    /**
     * @param  list<array{string, string, int}>  $variants  title, price, stock
     * @return array{title: string, status: string, total_inventory: int, tracks_inventory: bool, currency: string, variants: list<array{title: string, price: string, inventory_quantity: int|null, sku: string|null}>, admin_path: string}
     */
    private function product(string $title, string $status, array $variants, int $id): array
    {
        return [
            'title' => $title,
            'status' => $status,
            'total_inventory' => array_sum(array_column($variants, 2)),
            'tracks_inventory' => true,
            'currency' => 'USD',
            'variants' => array_map(fn (array $variant) => [
                'title' => $variant[0], 'price' => $variant[1], 'inventory_quantity' => $variant[2], 'sku' => null,
            ], $variants),
            'admin_path' => "/products/{$id}",
        ];
    }

    /**
     * @return list<array{number: string, created_at: CarbonImmutable, financial_status: string, fulfillment_status: string, total: string, currency: string, items: list<string>, tracking_number: string|null, admin_path: string}>
     */
    private function orders(Shop $shop): array
    {
        $now = CarbonImmutable::now($shop->timezone ?? 'UTC');
        $today = $now->startOfDay();

        /** Today's order is placed before "now" at any hour, so it never lies in the future. */
        $earlierToday = $now->subMinutes(30)->max($today);

        return [
            $this->order('1042', $today->subDays(3)->setTime(14, 20), 'paid', 'fulfilled', '79.00', ['Denim Jacket (M)'], '1Z999AA10123456784', 5001),
            $this->order('1055', $today->subDay()->setTime(10, 15), 'paid', 'unfulfilled', '64.00', ['Blue Hoodie (L)', 'Beanie'], null, 5002),
            $this->order('1056', $today->subDay()->setTime(18, 40), 'pending', 'unfulfilled', '79.00', ['Denim Jacket (L)'], null, 5003),
            $this->order('1057', $today->subDay()->setTime(21, 5), 'paid', 'partially_fulfilled', '124.00', ['Denim Jacket (S)', 'Blue Hoodie (M)'], '1Z999AA10123456791', 5004),
            $this->order('1058', $earlierToday, 'paid', 'unfulfilled', '45.00', ['Blue Hoodie (L)'], null, 5005),
        ];
    }

    /**
     * @param  list<string>  $items
     * @return array{number: string, created_at: CarbonImmutable, financial_status: string, fulfillment_status: string, total: string, currency: string, items: list<string>, tracking_number: string|null, admin_path: string}
     */
    private function order(string $number, CarbonImmutable $createdAt, string $payment, string $fulfillment, string $total, array $items, ?string $tracking, int $id): array
    {
        return [
            'number' => $number,
            'created_at' => $createdAt,
            'financial_status' => $payment,
            'fulfillment_status' => $fulfillment,
            'total' => $total,
            'currency' => 'USD',
            'items' => $items,
            'tracking_number' => $tracking,
            'admin_path' => "/orders/{$id}",
        ];
    }
}
