<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Product> */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        $cost = fake()->randomFloat(2, 0.2, 20);

        return [
            'category_id' => Category::factory(),
            'name' => fake()->words(2, true),
            'sku' => 'SKU-'.fake()->unique()->numerify('######'),
            'barcode' => fake()->unique()->numerify('############'),
            'description' => null,
            'cost_price' => $cost,
            'sell_price' => round($cost * 2.2, 2),
            'unit' => 'pcs',
            'track_stock' => true,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
