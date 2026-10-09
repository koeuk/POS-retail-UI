<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            // Public identity: what URLs and route bindings use. The numeric
            // id stays the key every FK points at, but never leaves the server.
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            /*
             * Nullable: a debt can be typed straight onto a customer —
             * "៛10,000, rice and oil" — without ringing products through the
             * till. Such a line has no product row behind it; product_name
             * (the snapshot every report groups on) carries what it was.
             */
            $table->foreignId('product_id')->nullable()->constrained()->restrictOnDelete();

            // Plain column, deliberately NOT a foreign key: product_variants is
            // deferred to v2, so the constraint has nothing to point at yet.
            $table->unsignedBigInteger('product_variant_id')->nullable();

            // Snapshots. Never join back to products for historical accuracy —
            // names get edited and prices change.
            $table->string('product_name');
            $table->decimal('unit_price', 12, 2);

            $table->integer('qty');
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();

            // Drives the sales-by-product report.
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
