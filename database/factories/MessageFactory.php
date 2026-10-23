<?php

namespace Database\Factories;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => MessageRole::Merchant,
            'content' => fake()->sentence(),
        ];
    }

    /**
     * A reply written by the bot.
     */
    public function fromAssistant(): static
    {
        return $this->state(fn () => ['role' => MessageRole::Assistant]);
    }
}
