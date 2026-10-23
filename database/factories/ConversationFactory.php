<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'staff_user_id' => (string) fake()->numberBetween(1000, 999999),
        ];
    }
}
