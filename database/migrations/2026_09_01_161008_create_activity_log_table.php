<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');

            // Which shop the action happened in. Null for admins acting
            // outside any store, and for console/seeder activity.
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();

            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();

            // Audit context Spatie's own table does not carry.
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();

            // The Activity screen filters on all three.
            $table->index('created_at');
            $table->index(['log_name', 'created_at']);
        });
    }

    /*
     * The published stub ships without a down(), so a rollback left the
     * table behind and the next migrate failed on "table already exists".
     * RefreshDatabase does exactly that between tests.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
