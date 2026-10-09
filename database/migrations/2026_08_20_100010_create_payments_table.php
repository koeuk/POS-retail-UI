<?php

use App\Enums\PaymentMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // Public identity: what URLs and route bindings use. The numeric
            // id stays the key every FK points at, but never leaves the server.
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('method', PaymentMethod::values());
            $table->decimal('amount', 12, 2);
            $table->string('reference_no')->nullable();
            $table->timestamps();

            // Payment-method breakdown on the reports page.
            $table->index('method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
