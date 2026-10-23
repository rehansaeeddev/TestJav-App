<?php

namespace Database\Factories;

use App\Enums\ChatIntent;
use App\Models\IntentDecision;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntentDecision>
 */
class IntentDecisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'part' => 0,
            'question' => fake()->sentence(),
            'final_intent' => ChatIntent::HowTo,
            'decided_by' => 'laya',
            'laya_choice' => ChatIntent::HowTo,
            'laya_confidence' => fake()->randomFloat(2, 0.3, 1),
        ];
    }
}
