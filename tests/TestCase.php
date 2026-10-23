<?php

namespace Tests;

use App\Services\Shopify\StoreData;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\FakeStoreData;

abstract class TestCase extends BaseTestCase
{
    protected const SHOPIFY_CLIENT_ID = 'test-client-id';

    protected const SHOPIFY_SECRET = 'test-client-secret';

    /**
     * Every test answers from the made-up store, so results never depend on a real shop.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(StoreData::class, FakeStoreData::class);
    }

    protected function configureShopifyApp(): void
    {
        config([
            'services.shopify.client_id' => self::SHOPIFY_CLIENT_ID,
            'services.shopify.client_secret' => self::SHOPIFY_SECRET,
        ]);
    }

    /**
     * Build a Shopify session token (JWT) the way App Bridge does.
     *
     * @param  array<string, mixed>  $claims  Overrides for the default valid claims.
     */
    protected function sessionToken(string $shop = 'shop-a.myshopify.com', array $claims = [], string $secret = self::SHOPIFY_SECRET, string $algorithm = 'HS256'): string
    {
        $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

        $header = $encode(json_encode(['alg' => $algorithm, 'typ' => 'JWT']));
        $payload = $encode(json_encode(array_merge([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => self::SHOPIFY_CLIENT_ID,
            'sub' => '42',
            'exp' => time() + 60,
            'nbf' => time() - 5,
            'iat' => time() - 5,
            'jti' => 'f8912129-1af6-4cad-9ca3-76b0f7621087',
            'sid' => 'session-id',
        ], $claims)));

        $signature = $encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }
}
