<?php

use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Support\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            /*
             * Public identity: what URLs and route bindings use. The numeric
             * id stays the key every FK points at, but never leaves the server.
             */
            $table->uuid('uuid')->unique();

            /*
             * Generated client-side before the order ever reaches the server.
             * The unique index is what makes retrying a sync safe: firstOrCreate
             * on this column collapses duplicate flushes into one order.
             */
            $table->string('client_uuid', 36)->unique();

            /* Server-generated at sync time: {store}-{register}-{YYMMDD}-{seq} */
            $table->string('order_no')->unique();

            $table->foreignId('store_id')->constrained()->restrictOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            /*
             * Why the sale happened — see App\Enums\SaleType. Indexed because
             * the reporter filters on it for every revenue figure.
             */
            $table->enum('sale_type', SaleType::values())->default(SaleType::Customer->value);

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);

            /* total = subtotal - discount_amount. Nothing is added on top. */
            $table->decimal('total', 12, 2)->default(0);

            /*
             * Snapshot of the shop currency the figures are in. The shop may
             * switch later; a past sale must not silently change what it was worth.
             */
            $table->char('currency', 3)->default(Currency::USD);

            /* paid_amount = SUM(payments.amount); change_amount = paid - total, cash only. */
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('change_amount', 12, 2)->default(0);

            $table->enum('status', OrderStatus::values())->default(OrderStatus::Completed->value);

            $table->timestamp('synced_at')->nullable();

            /*
             * When the sale actually happened on the tablet, which may be hours
             * before it reached the server. Reports must use this, not created_at.
             */
            $table->timestamp('created_offline_at')->nullable();

            $table->timestamps();

            $table->index(['store_id', 'created_at']);
            $table->index(['cashier_id', 'created_at']);
            $table->index('status');
            $table->index('sale_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
