<?php

namespace App\Services\Shopify;

use RuntimeException;

/**
 * A Shopify session token that failed verification. The message names the failed
 * check only, never the token itself.
 */
class InvalidSessionToken extends RuntimeException {}
