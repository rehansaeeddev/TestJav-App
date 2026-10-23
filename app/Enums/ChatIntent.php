<?php

namespace App\Enums;

/**
 * What the merchant wants. The values double as the option labels sent to the decision
 * engine, which reads them as well as the descriptions, so keep them descriptive.
 */
enum ChatIntent: string
{
    case HowTo = 'how_to_do_something_in_shopify';
    case FindOrder = 'find_a_specific_order';
    case OrderStats = 'count_or_summarize_orders';
    case ExplainPage = 'explain_the_current_page';
    case Products = 'ask_about_products_or_stock';
    case Policies = 'ask_about_store_policies';
    case StoreDetails = 'ask_about_store_details';
    case DiscountsAndCollections = 'ask_about_discounts_or_collections';
    case Other = 'other';

    /** Recognised by MessageParser::asksForGrowthAdvice(), not offered to the decision engine. */
    case GrowthAdvice = 'get_advice_to_grow_sales';

    /** The question the decision engine answers with one of these intents. */
    public const QUESTION = 'What does this Shopify store owner want from the assistant?';

    public function description(): string
    {
        return match ($this) {
            self::HowTo => 'Asks how to do a task in the Shopify admin, like creating an order, adjusting stock, refunding, adding a product or making a discount.',
            self::FindOrder => 'Asks about one particular order: where it is, its status, payment or shipping.',
            self::OrderStats => 'Asks how many orders there were, or for order totals, over a day or a period of time.',
            self::ExplainPage => 'Asks what the page they are looking at is, or what it is for.',
            self::Products => "Asks about their products: a product's price, variants or stock level, which products are low or out of stock, or how many products they have.",
            self::Policies => "Asks what their store's refund, return, shipping, privacy or terms policy says.",
            self::StoreDetails => 'Asks about the store itself: its name, currency, timezone, domain, contact email, or which Shopify plan it is on.',
            self::DiscountsAndCollections => 'Asks which discounts or discount codes are active, or about their collections.',
            self::Other => 'Anything else.',
            self::GrowthAdvice => 'Asks how to grow the business: more sales, customers or orders.',
        };
    }

    /**
     * Options for the decision engine, label => description.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->reject(fn (self $intent) => $intent === self::GrowthAdvice)
            ->mapWithKeys(fn (self $intent) => [$intent->value => $intent->description()])
            ->all();
    }
}
