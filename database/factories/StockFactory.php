<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Stock> */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'store_id' => Store::factory(),
            'qty' => 100,
            'low_stock_threshold' => 10,
        ];
    }
}
