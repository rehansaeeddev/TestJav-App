<?php

namespace App\Http\Middleware;

use App\Services\Shopify\InvalidSessionToken;
use App\Services\Shopify\SessionToken;
use App\Services\Shopify\ShopifyAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the embedded admin app with the Shopify session token in
 * the Authorization header. Sets the request attributes "shopify_shop" (the Shop) and
 * "shopify_user" (the staff member's Shopify user ID).
 */
class AuthenticateShopifySession
{
    public function __construct(private SessionToken $sessionToken, private ShopifyAuth $auth) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();

        try {
            $session = $this->sessionToken->verify($token);
            $shop = $this->auth->shopWithAccessToken($session['shop'], $token);
        } catch (InvalidSessionToken) {
            return response()
                ->json(['message' => 'Invalid or expired Shopify session token.'], Response::HTTP_UNAUTHORIZED)
                ->header('X-Shopify-Retry-Invalid-Session-Request', '1');
        }

        $request->attributes->set('shopify_shop', $shop);
        $request->attributes->set('shopify_user', $session['user']);

        return $next($request);
    }
}
