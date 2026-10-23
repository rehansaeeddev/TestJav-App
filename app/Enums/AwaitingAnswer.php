<?php

namespace App\Enums;

/**
 * The follow-up answer the bot asked the merchant for.
 */
enum AwaitingAnswer: string
{
    case OrderNumber = 'order_number';
    case DateRange = 'date_range';
    case PageName = 'page_name';
    case ProductName = 'product_name';
}
