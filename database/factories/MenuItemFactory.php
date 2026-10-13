<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuItemFactory extends Factory
{
    protected $model = MenuItem::class;

    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => fake()->unique()->words(2, true),
            'price_cents' => fake()->numberBetween(5000, 50000) * 100,
            'stock' => fake()->randomElement([-1, 0, 10, 20, 50]),
            'image_url' => null,
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }
}