<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gets the shop's offline access token by exchanging the merchant's session token
 * (token exchange). During a merchant request Shopify says to re-run the exchange
 * rather than use the refresh token, so this class never refreshes.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/token-exchange
 */
class ShopifyAuth
{
    /** Renew this long before the token actually expires. */
    private const EXPIRY_MARGIN_MINUTES = 5;

    /** How often to retry an exchange for scopes the merchant has not approved yet. */
    private const SCOPE_RECHECK_MINUTES = 10;

    public function __construct(private AdminApi $api) {}

    /**
     * The shop with a usable access token, exchanging the session token if needed.
     *
     * @throws InvalidSessionToken when Shopify rejects the session token.
     */
    public function shopWithAccessToken(string $domain, string $sessionToken): Shop
    {
        $shop = Shop::firstOrNew(['domain' => $domain]);

        /** Decided once, before the lock, so the cooldown key is not consumed twice. */
        $recheckScopes = $this->isMissingScopes($shop)
            && Cache::add("shopify-scope-recheck:{$domain}", true, now()->addMinutes(self::SCOPE_RECHECK_MINUTES));

        if (! $recheckScopes && $this->hasUsableToken($shop)) {
            return $shop;
        }

        /** One exchange per shop at a time: each exchange retires the previous token. */
        return Cache::lock("shopify-token-exchange:{$domain}", 20)->block(15, function () use ($domain, $sessionToken, $recheckScopes) {
            $shop = Shop::firstOrNew(['domain' => $domain]);

            if ($recheckScopes || ! $this->hasUsableToken($shop)) {
                $this->exchange($shop, $sessionToken);
            }

            return $shop;
        });
    }

    /**
     * An existing token issued before the merchant approved scopes the app now asks for.
     */
    private function isMissingScopes(Shop $shop): bool
    {
        if (! $shop->exists || blank($shop->access_token)) {
            return false;
        }

        $granted = array_map('trim', explode(',', (string) $shop->scopes));

        return array_diff(config('services.shopify.scopes'), $granted) !== [];
    }

    private function hasUsableToken(Shop $shop): bool
    {
        return $shop->exists
            && filled($shop->access_token)
            && ($shop->access_token_expires_at === null
                || $shop->access_token_expires_at->isAfter(now()->addMinutes(self::EXPIRY_MARGIN_MINUTES)));
    }

    private function exchange(Shop $shop, string $sessionToken): void
    {
        $response = Http::asForm()
            ->acceptJson()
            ->timeout(15)
            ->post("https://{$shop->domain}/admin/oauth/access_token", [
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
                'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token' => $sessionToken,
                'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'expiring' => '1',
            ]);

        if (in_array($response->status(), [400, 401], true)) {
            throw new InvalidSessionToken('Shopify rejected the session token during token exchange.');
        }

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new RuntimeException('Shopify token exchange failed with HTTP '.$response->status());
        }

        $grantedScopes = array_map('trim', explode(',', (string) $response->json('scope')));
        $missingScopes = array_diff(config('services.shopify.required_scopes'), $grantedScopes);

        if ($missingScopes !== []) {
            throw new RuntimeException('The app is missing access scopes: '.implode(', ', $missingScopes).'. Add them in shopify.app.toml and reinstall.');
        }

        $shop->fill([
            'access_token' => $response->json('access_token'),
            'access_token_expires_at' => $this->expiresAt($response->json('expires_in')),
            'refresh_token' => $response->json('refresh_token'),
            'refresh_token_expires_at' => $this->expiresAt($response->json('refresh_token_expires_in')),
            'scopes' => implode(',', $grantedScopes),
        ])->save();

        $shop->update([
            'timezone' => $this->api->query($shop, 'query { shop { ianaTimezone } }')['shop']['ianaTimezone'] ?? 'UTC',
        ]);
    }

    private function expiresAt(mixed $seconds): ?Carbon
    {
        return is_numeric($seconds) ? now()->addSeconds((int) $seconds) : null;
    }
}
