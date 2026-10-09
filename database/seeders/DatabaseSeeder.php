<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $store = Store::create([
            'name' => 'Main Store',
            'address' => '123 Central Market Street',
            'phone' => '+855 12 345 678',
        ]);

        Register::create([
            'store_id' => $store->id,
            'name' => 'Register 1',
            'is_active' => true,
        ]);

        $this->seedUsers($store);
        $this->seedSettings($store);

        // No catalogue: products and categories are the shop's own. For a
        // demo shelf, run `php artisan db:seed --class=DemoSeeder`.
    }

    private function seedUsers(Store $store): void
    {
        // Owner account. There is no separate "superadmin" role — admin is the
        // top of the ladder and already bypasses every policy check via the
        // before() hook, with no store binding so it sees all stores.
        User::create([
            'name' => 'Koeuk',
            'email' => 'koeukkos@gmail.com',
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
            'role' => Role::Admin,
            'store_id' => null,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
            'role' => Role::Admin,
            'store_id' => null,
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Manager',
            'email' => 'manager@gmail.com',
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
            'role' => Role::Manager,
            'store_id' => $store->id,
            'is_active' => true,
        ]);

        // A cashier must be store-bound — /pos cannot resolve stock without it.
        User::create([
            'name' => 'Cashier',
            'email' => 'cashier@gmail.com',
            'password' => Hash::make('12345678'),
            'email_verified_at' => now(),
            'role' => Role::Cashier,
            'store_id' => $store->id,
            'is_active' => true,
        ]);
    }

    private function seedSettings(Store $store): void
    {
        $defaults = [
            'receipt_header' => $store->name,
            'receipt_footer' => 'Thank you for shopping with us!',
            // Riel-native: prices are stored and shown in riel, exactly as
            // typed — this is a Cambodian shop, 2,500៛ means 2,500៛.
            'currency' => 'KHR',
            'riel_per_usd' => '4100',
        ];

        foreach ($defaults as $key => $value) {
            Setting::create(['key' => $key, 'value' => $value]);
        }
    }
}
