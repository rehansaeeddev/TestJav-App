<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Carbon\CarbonInterface;

/**
 * Read-only store data the bot can look up. Implemented by fake sample data for tests and
 * demos, and by the Shopify Admin API for a connected store.
 *
 * @phpstan-type Order array{number: string, created_at: CarbonInterface, financial_status: string, fulfillment_status: string, total: string, currency: string, items: list<string>, tracking_number: string|null, admin_path: string}
 * @phpstan-type Product array{title: string, status: string, total_inventory: int, tracks_inventory: bool, currency: string, variants: list<array{title: string, price: string, inventory_quantity: int|null, sku: string|null}>, admin_path: string}
 * @phpstan-type StockLevel array{product_title: string, variant_title: string, quantity: int, admin_path: string}
 * @phpstan-type StoreDetails array{name: string, email: string|null, contact_email: string|null, currency: string, timezone: string, domain: string|null, myshopify_domain: string, plan: string|null, country: string|null}
 * @phpstan-type Policy array{type: string, title: string, body: string, url: string|null}
 * @phpstan-type Discount array{title: string, summary: string|null, code: string|null, ends_at: CarbonInterface|null}
 * @phpstan-type Collection array{title: string, products_count: int, admin_path: string}
 */
interface StoreData
{
    /**
     * @return Order|null
     */
    public function findOrderByNumber(Shop $shop, string $orderNumber): ?array;

    /**
     * @return Order|null
     */
    public function latestOrder(Shop $shop): ?array;

    /**
     * Orders created in [from, to), optionally only those matching $filter ("unfulfilled", "over $100").
     */
    public function countOrders(Shop $shop, CarbonInterface $from, CarbonInterface $to, ?OrderFilter $filter = null): int;

    /**
     * Newest orders first, optionally only those created in [from, to).
     *
     * @return list<Order>
     */
    public function recentOrders(Shop $shop, ?CarbonInterface $from = null, ?CarbonInterface $to = null, int $limit = 10, ?OrderFilter $filter = null): array;

    /**
     * Products matching the search words, best match first.
     *
     * @return list<Product>
     */
    public function findProducts(Shop $shop, string $search, int $limit = 5): array;

    /**
     * Products of any status, A–Z by title; only those matching $filter's status and stock when given.
     *
     * @return list<Product>
     */
    public function listProducts(Shop $shop, int $limit = 20, ?ProductFilter $filter = null): array;

    /**
     * Variants of active, inventory-tracked products, lowest stock first. Untracked products
     * have no stock count, so they are never included.
     *
     * @return list<StockLevel>
     */
    public function lowestStockVariants(Shop $shop, int $limit = 50): array;

    /**
     * Money taken in orders created in [from, to): order totals after refunds and edits, including
     * shipping and tax. "complete" is false when there were more orders than one answer reads.
     *
     * @return array{total: float, currency: string, orders: int, complete: bool}
     */
    public function salesTotal(Shop $shop, CarbonInterface $from, CarbonInterface $to): array;

    /**
     * Products by units sold in orders created in the period, most sold first. Built from up to
     * 250 orders (Shopify only shows this app the last 60 days of orders).
     *
     * @return list<array{title: string, quantity: int, revenue: float, currency: string}>
     */
    public function bestSellers(Shop $shop, CarbonInterface $from, CarbonInterface $to, int $limit = 5): array;

    /**
     * @param  string|null  $status  ACTIVE, DRAFT or ARCHIVED; null counts all products.
     */
    public function countProducts(Shop $shop, ?string $status = null): int;

    /**
     * @return StoreDetails
     */
    public function storeDetails(Shop $shop): array;

    /**
     * Policies the merchant has filled in, body as plain text.
     *
     * @return list<Policy>
     */
    public function policies(Shop $shop): array;

    /**
     * @return list<Discount>
     */
    public function activeDiscounts(Shop $shop, int $limit = 20): array;

    /**
     * @return list<Collection>
     */
    public function collections(Shop $shop, int $limit = 20): array;
}
