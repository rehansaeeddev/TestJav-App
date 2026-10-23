<?php

namespace Tests\Feature;

use App\Services\Shopify\InvalidSessionToken;
use App\Services\Shopify\SessionToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SessionTokenTest extends TestCase
{
    public function test_valid_token_returns_the_shop_and_user(): void
    {
        $this->configureShopifyApp();

        $session = (new SessionToken)->verify($this->sessionToken('shop-a.myshopify.com'));

        $this->assertSame(['shop' => 'shop-a.myshopify.com', 'user' => '42'], $session);
    }

    /**
     * @return array<string, array{\Closure(self): string, string}>
     */
    public static function invalidTokens(): array
    {
        return [
            'expired' => [fn (self $test) => $test->sessionToken(claims: ['exp' => time() - 60]), 'Token expired.'],
            'not valid yet' => [fn (self $test) => $test->sessionToken(claims: ['nbf' => time() + 60]), 'Token not valid yet.'],
            'other app' => [fn (self $test) => $test->sessionToken(claims: ['aud' => 'someone-else']), 'Token is for another app.'],
            'wrong secret' => [fn (self $test) => $test->sessionToken(secret: 'attacker-secret'), 'Invalid signature.'],
            'alg none' => [fn (self $test) => $test->sessionToken(algorithm: 'none'), 'Unsupported signing algorithm.'],
            'issuer mismatch' => [fn (self $test) => $test->sessionToken(claims: ['iss' => 'https://shop-b.myshopify.com/admin']), 'Issuer and destination do not match.'],
            'not a shopify store' => [fn (self $test) => $test->sessionToken('evil.example.com'), 'Destination is not a Shopify store.'],
            'garbage' => [fn () => 'not-a-jwt', 'Malformed token.'],
        ];
    }

    /**
     * @param  \Closure(self): string  $makeToken
     */
    #[DataProvider('invalidTokens')]
    public function test_invalid_tokens_are_rejected(\Closure $makeToken, string $reason): void
    {
        $this->configureShopifyApp();

        $this->expectException(InvalidSessionToken::class);
        $this->expectExceptionMessage($reason);

        (new SessionToken)->verify($makeToken($this));
    }

    public function test_tampered_payload_is_rejected(): void
    {
        $this->configureShopifyApp();
        [$header, , $signature] = explode('.', $this->sessionToken('shop-a.myshopify.com'));
        [, $otherPayload] = explode('.', $this->sessionToken('shop-b.myshopify.com'));

        $this->expectExceptionMessage('Invalid signature.');

        (new SessionToken)->verify("{$header}.{$otherPayload}.{$signature}");
    }
}
