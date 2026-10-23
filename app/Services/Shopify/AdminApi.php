<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

/**
 * Calls the Shopify GraphQL Admin API for one shop. Errors carry status codes and
 * GraphQL error codes only, never response data or tokens.
 */
class AdminApi
{
    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> The "data" part of the response.
     */
    public function query(Shop $shop, string $query, array $variables = []): array
    {
        $response = Http::withHeaders(['X-Shopify-Access-Token' => (string) $shop->access_token])
            ->acceptJson()
            ->timeout(20)
            ->post("https://{$shop->domain}/admin/api/".config('services.shopify.api_version').'/graphql.json', array_filter([
                'query' => $query,
                'variables' => $variables,
            ]));

        if ($response->status() === 401) {
            $shop->update(['access_token' => null, 'access_token_expires_at' => null]);

            throw new ShopifyApiError('Shopify rejected the access token. It will be renewed on the next request.');
        }

        if ($response->failed()) {
            throw new ShopifyApiError('Shopify Admin API request failed with HTTP '.$response->status());
        }

        /** GraphQL reports problems such as ACCESS_DENIED or THROTTLED with HTTP 200. */
        if (filled($errors = $response->json('errors'))) {
            $codes = collect(is_array($errors) ? $errors : [$errors])
                ->map(fn ($error) => is_array($error) ? ($error['extensions']['code'] ?? null) : null)
                ->filter()
                ->unique()
                ->values()
                ->all();

            throw new ShopifyApiError('Shopify Admin API returned errors: '.(implode(', ', $codes) ?: 'unknown'), $codes);
        }

        return $response->json('data') ?? [];
    }
}
