<?php

namespace App\Services\Shopify;

/**
 * Verifies the session token (an HS256 JWT signed with the app's client secret) that
 * App Bridge issues to the embedded admin app. Written by hand so no JWT library is needed.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
class SessionToken
{
    /** Seconds of clock difference allowed between Shopify and this server. */
    private const LEEWAY_SECONDS = 10;

    /**
     * @return array{shop: string, user: string}
     *
     * @throws InvalidSessionToken
     */
    public function verify(string $token): array
    {
        $clientId = config('services.shopify.client_id');
        $secret = config('services.shopify.client_secret');

        if (blank($clientId) || blank($secret)) {
            throw new InvalidSessionToken('Shopify client ID or secret is not configured.');
        }

        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidSessionToken('Malformed token.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = json_decode($this->base64UrlDecode($encodedHeader), true);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidSessionToken('Unsupported signing algorithm.');
        }

        $expected = hash_hmac('sha256', "{$encodedHeader}.{$encodedPayload}", $secret, true);

        if (! hash_equals($expected, $this->base64UrlDecode($encodedSignature))) {
            throw new InvalidSessionToken('Invalid signature.');
        }

        $claims = json_decode($this->base64UrlDecode($encodedPayload), true);

        if (! is_array($claims)) {
            throw new InvalidSessionToken('Malformed claims.');
        }

        $now = time();

        if (! is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::LEEWAY_SECONDS) {
            throw new InvalidSessionToken('Token expired.');
        }

        if (! is_int($claims['nbf'] ?? null) || $claims['nbf'] > $now + self::LEEWAY_SECONDS) {
            throw new InvalidSessionToken('Token not valid yet.');
        }

        if (($claims['aud'] ?? null) !== $clientId) {
            throw new InvalidSessionToken('Token is for another app.');
        }

        $issuerHost = parse_url((string) ($claims['iss'] ?? ''), PHP_URL_HOST);
        $shop = parse_url((string) ($claims['dest'] ?? ''), PHP_URL_HOST);

        if (! is_string($shop) || $issuerHost !== $shop) {
            throw new InvalidSessionToken('Issuer and destination do not match.');
        }

        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/', $shop)) {
            throw new InvalidSessionToken('Destination is not a Shopify store.');
        }

        if (blank($claims['sub'] ?? null)) {
            throw new InvalidSessionToken('Token has no user.');
        }

        return ['shop' => $shop, 'user' => (string) $claims['sub']];
    }

    private function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');

        return (string) base64_decode($value.str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
}
