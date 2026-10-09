<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The suppliers the shop buys from. A product names its vendor, and a
         * vendor may be given staff accounts of its own (role `vendor`).
         */
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
        });

        // Removing a vendor leaves its products on the shelf, just unassigned.
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('category_id')
                ->constrained()->nullOnDelete();
        });

        // The vendor a `vendor`-role account belongs to. Restrict, so a vendor
        // with live accounts cannot vanish from under them.
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('store_id')
                ->constrained()->restrictOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', Role::values())->default(Role::Cashier->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
        });

        Schema::dropIfExists('vendors');

        // Vendor accounts cannot survive the role going away.
        DB::table('users')->where('role', 'vendor')->update(['role' => Role::Cashier->value]);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'manager', 'cashier'])->default(Role::Cashier->value)->change();
        });
    }
};
