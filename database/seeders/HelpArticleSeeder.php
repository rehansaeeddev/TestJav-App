<?php

namespace Database\Seeders;

use App\Models\HelpArticle;
use Illuminate\Database\Seeder;

/**
 * How-to entries written in our own words, each linking to the official Shopify Help Center
 * article (URLs checked 2026-10-21). Safe to re-run: rows are matched by slug.
 */
class HelpArticleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach ([...$this->articles(), ...$this->adminGuides()] as $article) {
            HelpArticle::updateOrCreate(['slug' => $article['slug']], $article);
        }
    }

    /**
     * Detailed guides for every admin section, one per Help Center page (URLs checked 2026-10-22).
     *
     * @return list<array<string, string|null>>
     */
    private function adminGuides(): array
    {
        return json_decode(file_get_contents(__DIR__.'/data/help-articles.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, string>>
     */
    private function articles(): array
    {
        return [
            [
                'slug' => 'create-order',
                'title' => 'Create an order for a customer',
                'admin_page' => 'Orders → Create order',
                'admin_path' => '/draft_orders/new',
                'page_summary' => 'The Create order page builds a draft order. You add products, a customer, discounts and shipping, then choose how to get paid. Once it is paid, the draft becomes a regular order on the Orders page.',
                'steps' => "1. In your Shopify admin, open Orders.\n2. Click Create order.\n3. Add the products and choose the customer.\n4. Add a discount or shipping if needed.\n5. Choose how to get paid: send the customer an invoice with a checkout link, enter their card payment, or mark it as paid if you already received the money.",
                'keywords' => 'new order, manual order, draft order, phone order, invoice, make an order, place order',
                'source_url' => 'https://help.shopify.com/en/manual/fulfillment/managing-orders/create-orders',
            ],
            [
                'slug' => 'find-order',
                'title' => 'Find an order',
                'admin_page' => 'Orders',
                'admin_path' => '/orders',
                'page_summary' => 'The Orders page lists every order in your store. You can search by order number, customer name or product, and filter by status, payment method or date. Click an order to see its items, payment, shipping and timeline.',
                'steps' => "1. Open Orders in your Shopify admin.\n2. Type the order number (for example 1042), the customer's name, or a product in the search bar.\n3. Use the filters to narrow by status or date.\n4. Click the order to open it.",
                'keywords' => 'find order, find an order, search order, look up, locate, order number, filter orders, order list',
                'source_url' => 'https://help.shopify.com/en/manual/fulfillment/managing-orders',
            ],
            [
                'slug' => 'adjust-inventory',
                'title' => 'Adjust stock (inventory) quantities',
                'admin_page' => 'Products → Inventory',
                'admin_path' => '/products/inventory',
                'page_summary' => 'The Inventory page shows how much stock you have of each product variant. You can change quantities one product at a time, filter and sort the list, save filtered views, and see a history of every adjustment: who made it, when, and why.',
                'steps' => "1. In your Shopify admin, go to Products → Inventory.\n2. Find the product by searching or filtering.\n3. Enter the new quantity for the variant and save the change.\n4. To change many products at once, use the bulk editor.",
                'keywords' => 'stock, inventory, quantity, available, update stock, restock, stock count, stocktake',
                'source_url' => 'https://help.shopify.com/en/manual/inventory-and-locations/adjusting-inventory',
            ],
            [
                'slug' => 'refund-order',
                'title' => 'Refund an order',
                'admin_page' => 'Orders → (open the order) → Refund',
                'admin_path' => '/orders',
                'page_summary' => "An order's page shows its items, payment, fulfillment and timeline. From there you can refund, restock, or fulfill the order.",
                'steps' => "1. Open Orders and click the order.\n2. Click Refund.\n3. Enter how many of each item to refund. Use 0 for items you are not refunding (a partial refund).\n4. Keep \"Restock items\" ticked to put the items back in stock.\n5. Optionally refund shipping and add a reason (customers don't see the reason).\n6. Check the refund amount and click Refund.",
                'keywords' => 'refund, issue a refund, issue refund, refund a customer, money back, return, partial refund, partially refund, restock, cancel payment',
                'source_url' => 'https://help.shopify.com/en/manual/fulfillment/managing-orders/refunding-orders',
            ],
            [
                'slug' => 'fulfill-order',
                'title' => 'Fulfill (ship) an order',
                'admin_page' => 'Orders → (open the order)',
                'admin_path' => '/orders',
                'page_summary' => "An order's page shows its items, payment, fulfillment and timeline. Unfulfilled items can be marked as fulfilled there, with tracking details.",
                'steps' => "1. Open Orders and click an unfulfilled order.\n2. Click the button to fulfill the items.\n3. Add the tracking number and carrier if you have them.\n4. Choose whether to notify the customer, then confirm.",
                'keywords' => 'ship, fulfill, fulfil, partially fulfill, partial fulfillment, fulfill some items, tracking number, add tracking, send order, dispatch, mark as fulfilled, shipping label',
                'source_url' => 'https://help.shopify.com/en/manual/fulfillment/fulfilling-orders',
            ],
            [
                'slug' => 'add-product',
                'title' => 'Add a product',
                'admin_page' => 'Products → Add product',
                'admin_path' => '/products/new',
                'page_summary' => "The product page is where you set a product's title, description, images, price, variants such as size or color, inventory and shipping details.",
                'steps' => "1. Go to Products.\n2. Click Add product.\n3. Enter the title, description and images.\n4. Set the price, and add variants such as size or color if needed.\n5. Set inventory and shipping details.\n6. Save.",
                'keywords' => 'new product, create product, upload product, listing, variants, price, images',
                'source_url' => 'https://help.shopify.com/en/manual/products/add-update-products',
            ],
            [
                'slug' => 'create-discount',
                'title' => 'Create a discount',
                'admin_page' => 'Discounts → Create discount',
                'admin_path' => '/discounts',
                'page_summary' => 'The Discounts page lists your discount codes and automatic discounts, with their status and how often they were used.',
                'steps' => "1. Go to Discounts.\n2. Click Create discount.\n3. Pick a type, for example amount off products, amount off the order, buy X get Y, or free shipping.\n4. Choose a code or make it automatic, then set the value, who can use it, and the dates.\n5. Save.",
                'keywords' => 'discount, coupon, code, sale, promo, promotion, free shipping, percentage off',
                'source_url' => 'https://help.shopify.com/en/manual/discounts/discount-types',
            ],
            [
                'slug' => 'customers',
                'title' => 'View and manage customers',
                'admin_page' => 'Customers',
                'admin_path' => '/customers',
                'page_summary' => 'The Customers page lists everyone who has ordered or signed up, with their contact details, number of orders and amount spent.',
                'steps' => "1. Go to Customers.\n2. Search by name or email, or use the filters.\n3. Click a customer to see their orders and details.",
                'keywords' => 'customer, buyer, client, contact, email, customer list',
                'source_url' => 'https://help.shopify.com/en/manual/customers',
            ],
            [
                'slug' => 'finance',
                'title' => 'Finance',
                'admin_page' => 'Finance',
                'admin_path' => null,
                'page_summary' => "Finance brings Shopify's money tools together: Shopify Balance, Shopify Credit, Shopify Capital, Bill Pay and Shopify Tax. It's where you review your earnings, spending and any repayments you owe in one place.",
                'steps' => "1. In your Shopify admin, click Finance in the left menu.\n2. Review your earnings, spending and repayments.\n3. Open the tool you need, such as Shopify Balance or Capital.",
                'keywords' => 'finance, money, payouts, balance, shopify balance, capital, credit, bill pay, earnings, repayments',
                'source_url' => 'https://help.shopify.com/en/manual/finance',
            ],
            [
                'slug' => 'markets',
                'title' => 'Markets',
                'admin_page' => 'Markets',
                'admin_path' => '/markets',
                'page_summary' => "Markets lets you change how shoppers experience your store based on where they are, their customer group, retail location or sales channel. For each market you can set currency, language, prices, which products are available, domains and taxes. A backup region covers customers who don't match any market.",
                'steps' => "1. In your Shopify admin, click Markets in the left menu.\n2. Open a market or create a new one and choose who it applies to.\n3. Adjust settings such as currency, language, pricing or product availability.",
                'keywords' => 'markets, international, countries, regions, currency, language, selling abroad, global',
                'source_url' => 'https://help.shopify.com/en/manual/markets',
            ],
            [
                'slug' => 'online-store',
                'title' => 'Online Store',
                'admin_page' => 'Online Store',
                'admin_path' => '/themes',
                'page_summary' => 'The Online Store is the sales channel for your website. There you choose and edit your theme, create pages, publish a blog, organize navigation menus and filters, and connect a domain.',
                'steps' => "1. In your Shopify admin, click Online Store under Sales channels.\n2. Use Themes to change how your store looks.\n3. Use Pages, Blog posts and Navigation to manage content and menus.",
                'keywords' => 'online store, website, theme, themes, pages, blog, navigation, menu, domain, design',
                'source_url' => 'https://help.shopify.com/en/manual/online-store',
            ],
            [
                'slug' => 'payments',
                'title' => 'Payment settings',
                'admin_page' => 'Settings → Payments',
                'admin_path' => '/settings/payments',
                'page_summary' => 'Payment settings is where you choose how customers can pay at checkout: credit cards through Shopify Payments or another provider, and options like PayPal, Apple Pay or Shop Pay.',
                'steps' => "1. In your Shopify admin, go to Settings → Payments.\n2. Set up Shopify Payments or another payment provider.\n3. Turn on extra payment methods such as PayPal or Shop Pay.",
                'keywords' => 'payments, payment methods, payment provider, shopify payments, paypal, apple pay, shop pay, checkout, gateway',
                'source_url' => 'https://help.shopify.com/en/manual/payments',
            ],
            [
                'slug' => 'shipping-settings',
                'title' => 'Set up shipping and delivery settings',
                'admin_page' => 'Settings → Shipping and delivery',
                'admin_path' => '/settings/shipping',
                'page_summary' => 'Shipping and delivery settings is where you decide where you ship and what customers pay for it, and set up options like local delivery or pickup.',
                'steps' => "1. In your Shopify admin, go to Settings → Shipping and delivery.\n2. Set the places you ship to and the shipping rates.\n3. Turn on local delivery or pickup if you offer them.",
                'keywords' => 'shipping settings, shipping rates, shipping zones, delivery, local delivery, pickup, shipping cost',
                'source_url' => 'https://help.shopify.com/en/manual/fulfillment/setup',
            ],
            [
                'slug' => 'taxes',
                'title' => 'Taxes and duties',
                'admin_page' => 'Settings → Taxes and duties',
                'admin_path' => '/settings/taxes',
                'page_summary' => 'Taxes and duties settings is where you control how taxes are charged for the places you sell to.',
                'steps' => "1. In your Shopify admin, go to Settings → Taxes and duties.\n2. Choose the region you want to review.\n3. Check or change how taxes are collected there.",
                'keywords' => 'taxes, tax, vat, gst, sales tax, duties, tax settings',
                'source_url' => 'https://help.shopify.com/en/manual/taxes',
            ],
            [
                'slug' => 'apps',
                'title' => 'Apps',
                'admin_page' => 'Apps',
                'admin_path' => '/apps',
                'page_summary' => 'The Apps page lists the apps installed on your store. From there you can open or manage them, and find new apps in the Shopify App Store.',
                'steps' => "1. In your Shopify admin, click Apps in the left menu.\n2. Open an installed app to use or manage it.\n3. Browse the Shopify App Store to add new apps.",
                'keywords' => 'apps, installed apps, app store, add app, uninstall app, integrations',
                'source_url' => 'https://help.shopify.com/en/manual/apps',
            ],
            [
                'slug' => 'store-settings',
                'title' => 'Store settings',
                'admin_page' => 'Settings → General',
                'admin_path' => '/settings/general',
                'page_summary' => "General settings hold your store's basic business details, such as the store name, contact email, address, currency and time zone.",
                'steps' => "1. In your Shopify admin, click Settings at the bottom of the left menu.\n2. Open General.\n3. Update your store details and save.",
                'keywords' => 'settings, general settings, store name, store details, business details, address, time zone, currency settings',
                'source_url' => 'https://help.shopify.com/en/manual/intro-to-shopify/initial-setup/setup-business-settings',
            ],
            [
                'slug' => 'analytics',
                'title' => 'See sales reports',
                'admin_page' => 'Analytics',
                'admin_path' => '/analytics',
                'page_summary' => "Analytics shows your store's performance, such as sales, orders and visitors, with reports you can filter by date.",
                'steps' => "1. Go to Analytics.\n2. Pick a date range.\n3. Open Reports for detailed breakdowns, such as sales by product.",
                'keywords' => 'report, reports, sales, revenue, analytics, stats, performance, dashboard',
                'source_url' => 'https://help.shopify.com/en/manual/reports-and-analytics',
            ],
        ];
    }
}
