<?php

namespace App\Payments;

use App\Enums\QrChargeStatus;
use App\Models\Order;
use App\Models\QrCharge;
use App\Models\Register;
use App\Models\Store;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens to a QR charge, whichever provider minted it.
 *
 * Controllers and the reconcile command go through here, so the audit trail
 * reads the same whether a payment was confirmed by the till's poll, the
 * nightly sweep or a cashier's thumb.
 */
class QrPayments
{
    public function __construct(private readonly QrGateways $gateways) {}

    public function open(User $cashier, int $storeId, ?int $registerId, string $amount): QrCharge
    {
        $gateway = $this->gateways->current();

        if (! $gateway->verifies()) {
            throw new GatewayException('This shop takes QR with the static code — no per-sale QR to create.');
        }

        $currency = Currency::current()->code;
        $expiresAt = now()->addSeconds(config('payments.qr_ttl_seconds', 180));
        $register = $registerId ? Register::where('store_id', $storeId)->find($registerId) : null;

        $issued = $gateway->createCharge(new ChargeRequest(
            amount: $amount,
            currency: $currency,
            expiresAt: $expiresAt,
            // Shows on the customer's banking app as the payment's note — the
            // store and till names help the shop match a statement line later.
            storeLabel: Store::whereKey($storeId)->value('name'),
            terminalLabel: $register?->name,
        ));

        return QrCharge::create([
            'provider' => $gateway->key(),
            'reference' => $issued->reference,
            'qr' => $issued->qr,
            'amount' => $amount,
            'currency' => $currency,
            'status' => QrChargeStatus::Pending,
            'store_id' => $storeId,
            'register_id' => $register?->id,
            'cashier_id' => $cashier->id,
            'meta' => $issued->meta ?: null,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * Ask the provider again, unless the answer can no longer change.
     * Throws GatewayException when the provider cannot be reached — the
     * caller decides whether that is worth showing.
     */
    public function refresh(QrCharge $charge): QrCharge
    {
        // Bakong's free tier allows 100 checks a day, so two tills polling
        // one charge, or one polling fast, must not multiply calls.
        if ($charge->status->isFinal() || $charge->checked_at?->gt(now()->subSeconds(8))) {
            return $charge;
        }

        $result = $this->gateways->get($charge->provider)->checkStatus($charge);

        if ($charge->applyStatus($result)) {
            AuditLog::money("QR payment received ({$charge->provider})", $charge, [
                'amount' => (string) $charge->amount,
                'currency' => $charge->currency,
                'provider' => $charge->provider,
                'provider_ref' => $charge->provider_ref,
                'order_id' => $charge->order_id,
            ], 'qr_paid');
        } elseif ($charge->status === QrChargeStatus::Failed) {
            AuditLog::money("QR payment flagged ({$charge->provider})", $charge, [
                'amount' => (string) $charge->amount,
                'reason' => $result->message,
            ], 'qr_failed');
        }

        return $charge;
    }

    /** A cashier vouches for a payment the provider has not confirmed. */
    public function confirmManually(QrCharge $charge, User $cashier): QrCharge
    {
        if ($charge->status->isSettled()) {
            return $charge;
        }

        $charge->update([
            'status' => QrChargeStatus::Manual,
            'confirmed_by' => $cashier->id,
            'paid_at' => now(),
        ]);

        AuditLog::money('QR payment confirmed by hand', $charge, [
            'amount' => (string) $charge->amount,
            'currency' => $charge->currency,
            'provider' => $charge->provider,
            'confirmed_by' => $cashier->name,
        ], 'qr_manual');

        return $charge;
    }

    public function cancel(QrCharge $charge): QrCharge
    {
        if ($charge->status === QrChargeStatus::Pending) {
            $charge->update(['status' => QrChargeStatus::Cancelled]);
        }

        return $charge;
    }

    /**
     * Tie a synced sale to the charge its QR payment came from. Called inside
     * the sync transaction; matching on reference_no is what makes it work
     * for a sale that sat in the offline queue for an hour.
     */
    public function attachToOrder(Order $order, ?string $reference): void
    {
        if (! $reference) {
            return;
        }

        QrCharge::where('reference', $reference)
            ->whereNull('order_id')
            ->where('store_id', $order->store_id)
            ->update(['order_id' => $order->id]);
    }

    /**
     * Re-ask the provider about every charge still in doubt. Catches the
     * customer who paid as the till gave up, and the till that closed before
     * its poll came back. Returns how many turned out to be paid.
     */
    public function reconcile(int $hours = 2): int
    {
        $paid = 0;

        QrCharge::query()
            ->whereIn('status', [QrChargeStatus::Pending, QrChargeStatus::Expired, QrChargeStatus::Cancelled])
            ->where('created_at', '>=', now()->subHours($hours))
            /*
             * One look per charge, a minute after it expired, and never again.
             * A late payer pays within seconds of the QR vanishing, not hours
             * later — and with 100 checks a day, re-asking about every
             * abandoned QR on every sweep would eat the till's budget.
             */
            ->where('expires_at', '<=', now()->subMinute())
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhereColumn('checked_at', '<', 'expires_at'))
            ->whereIn('provider', collect($this->gateways->options())->where('verifies', true)->pluck('key'))
            ->orderBy('id')
            ->each(function (QrCharge $charge) use (&$paid) {
                try {
                    // Expired and cancelled are asked too: a customer can pay
                    // as the cashier gives up. applyStatus() only moves them
                    // if the provider says the money actually arrived.
                    DB::transaction(fn () => $this->refresh($charge));

                    if ($charge->status === QrChargeStatus::Paid) {
                        $paid++;
                    }
                } catch (GatewayException) {
                    // Provider down: leave the row as it is for the next sweep.
                }
            });

        return $paid;
    }
}
