<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only webhooks signed by Shopify: base64 HMAC-SHA256 of the raw body
 * with the app's client secret, in the X-Shopify-Hmac-Sha256 header.
 */
class VerifyShopifyWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.shopify.client_secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        $validShop = (bool) preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', (string) $request->header('X-Shopify-Shop-Domain'));

        if ($secret === '' || ! $validShop || ! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
