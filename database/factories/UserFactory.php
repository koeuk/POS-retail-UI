<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),

            // Set explicitly rather than relying on the column defaults.
            // create() does not read DB-level defaults back into the model,
            // so an unset is_active reads as null and the role middleware
            // correctly treats that as deactivated.
            'role' => Role::Admin,
            'store_id' => null,
            'is_active' => true,
        ];
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin, 'store_id' => null]);
    }

    public function manager(Store|int|null $store = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Manager,
            'store_id' => $store instanceof Store ? $store->id : $store,
        ]);
    }

    /** Cashiers must be store-bound — /pos cannot resolve stock without one. */
    public function cashier(Store|int|null $store = null): static
    {
        return $this->state(fn () => [
            'role' => Role::Cashier,
            'store_id' => $store instanceof Store
                ? $store->id
                : ($store ?? Store::factory()),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
