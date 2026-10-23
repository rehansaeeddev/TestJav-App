<?php

namespace App\Services\Shopify;

use RuntimeException;

/**
 * The Admin API refused or failed a request. Carries GraphQL error codes only
 * (for example ACCESS_DENIED or THROTTLED), never response data.
 */
class ShopifyApiError extends RuntimeException
{
    /**
     * @param  list<string>  $codes
     */
    public function __construct(string $message, public readonly array $codes = [])
    {
        parent::__construct($message);
    }

    public function isAccessDenied(): bool
    {
        return in_array('ACCESS_DENIED', $this->codes, true);
    }
}
