<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => fake()->unique()->slug(2).'.myshopify.com',
            'access_token' => 'shpat_'.Str::random(32),
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_'.Str::random(32),
            'refresh_token_expires_at' => now()->addDays(90),
            'scopes' => 'read_orders,read_products,read_inventory,read_discounts,read_legal_policies',
            'timezone' => 'UTC',
        ];
    }
}
