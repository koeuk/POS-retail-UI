<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Vendor> */
class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'contact_name' => fake()->name(),
            'phone' => fake()->numerify('0## ### ###'),
            'email' => fake()->unique()->companyEmail(),
            'is_active' => true,
        ];
    }
}
