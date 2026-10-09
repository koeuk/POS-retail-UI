<?php

namespace App\Http\Controllers;

use App\Models\QrCharge;
use App\Payments\GatewayException;
use App\Payments\PaymentSettings;
use App\Payments\QrImage;
use App\Payments\QrPayments;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Per-sale QR codes for the /pos payment screen.
 *
 * Same door as the rest of pos/data: session cookie, CSRF, permission:pos.
 * The provider's credentials stay on this side — the till only ever sees a
 * QR and a status, so a cashier's tablet holds no bank token to lose.
 *
 * Unlike the order queue, none of this works offline and none of it needs
 * to: with no network the till falls back to the static QR in its feed.
 */
class PosQrController extends Controller
{
    public function __construct(private readonly QrPayments $payments) {}

    public function store(Request $request): JsonResponse
    {
        $decimals = Currency::current()->decimals;

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999', "decimal:0,{$decimals}"],
            'register_id' => ['nullable', 'integer'],
            'store_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();
        // A bound cashier charges into their own store, whatever the till says.
        $storeId = $user->store_id ?: ($data['store_id'] ?? null);

        abort_unless($storeId, 422, 'No store for this till.');

        try {
            $charge = DB::transaction(fn () => $this->payments->open(
                $user,
                (int) $storeId,
                $data['register_id'] ?? null,
                number_format((float) $data['amount'], $decimals, '.', ''),
            ));
        } catch (GatewayException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($this->present($charge, withQr: true), 201);
    }

    /**
     * The till's poll. A provider hiccup is not an error here — the QR is
     * still on screen and the next poll may get through — so it comes back
     * as "still pending" with a note.
     */
    public function show(Request $request, QrCharge $charge): JsonResponse
    {
        $this->authorizeCharge($request, $charge);

        $notice = null;

        try {
            DB::transaction(fn () => $this->payments->refresh($charge));
        } catch (GatewayException $e) {
            $notice = $e->getMessage();
        }

        return response()->json($this->present($charge) + ['notice' => $notice]);
    }

    public function confirm(Request $request, QrCharge $charge): JsonResponse
    {
        $this->authorizeCharge($request, $charge);

        abort_unless(PaymentSettings::allowsManualConfirm(), 403, 'This shop only accepts QR payments the bank has confirmed.');

        DB::transaction(fn () => $this->payments->confirmManually($charge, $request->user()));

        return response()->json($this->present($charge));
    }

    public function cancel(Request $request, QrCharge $charge): JsonResponse
    {
        $this->authorizeCharge($request, $charge);

        $this->payments->cancel($charge);

        return response()->json($this->present($charge));
    }

    /** A store-bound cashier only ever sees their own store's charges. */
    private function authorizeCharge(Request $request, QrCharge $charge): void
    {
        $storeId = $request->user()->store_id;

        abort_if($storeId && $charge->store_id !== $storeId, 404);
    }

    private function present(QrCharge $charge, bool $withQr = false): array
    {
        return array_filter([
            'id' => $charge->uuid,
            'provider' => $charge->provider,
            'reference' => $charge->reference,
            'amount' => (string) $charge->amount,
            'currency' => $charge->currency,
            'status' => $charge->status->value,
            'settled' => $charge->status->isSettled(),
            'expires_at' => $charge->expires_at?->toIso8601String(),
            'svg' => $withQr ? QrImage::svg($charge->qr) : null,
        ], fn ($v) => $v !== null);
    }
}
