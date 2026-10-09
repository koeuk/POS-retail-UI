<?php

use App\Enums\QrChargeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per QR shown to a customer — the paper trail between "the till
 * displayed a code for ៛16,400" and "the bank says it was paid". It outlives
 * the sale on purpose: an expired QR that is paid late is found here by the
 * reconcile sweep, even though the till moved on long ago.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_charges', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('provider', 32);

            // What the provider looks the charge up by (Bakong: the QR's MD5).
            // Also what the till writes into payments.reference_no, which is
            // how a synced sale finds its charge.
            $table->string('reference', 128);
            $table->text('qr');

            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->string('status', 16)->default(QrChargeStatus::Pending->value);

            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('register_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            // Set when a cashier confirmed it without the provider's word.
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('provider_ref')->nullable();
            $table->string('payer')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'reference']);
            // The reconcile sweep: "unsettled charges from the last day".
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_charges');
    }
};
