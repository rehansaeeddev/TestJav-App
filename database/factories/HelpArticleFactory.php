<?php

namespace Database\Factories;

use App\Models\HelpArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HelpArticle>
 */
class HelpArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(3),
            'title' => fake()->sentence(4),
            'admin_page' => 'Orders',
            'admin_path' => '/orders',
            'page_summary' => fake()->paragraph(),
            'steps' => fake()->paragraph(),
            'keywords' => implode(', ', fake()->words(5)),
            'source_url' => 'https://help.shopify.com/en/manual',
        ];
    }
}
