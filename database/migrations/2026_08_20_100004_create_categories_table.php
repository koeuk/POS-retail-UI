<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            // Public identity: what URLs and route bindings use. The numeric
            // id stays the key every FK points at, but never leaves the server.
            $table->uuid('uuid')->unique();
            // Flat on purpose: a category is just a category. The two-level
            // tree this table once carried was more structure than the POS
            // grid ever needed.
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
