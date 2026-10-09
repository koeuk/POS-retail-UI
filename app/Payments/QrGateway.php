<?php

namespace App\Payments;

use App\Models\QrCharge;

/**
 * One QR payment provider.
 *
 * The till never talks to a provider directly — it asks the server for a
 * charge, shows whatever QR comes back, and polls. So a new provider (ABA
 * PayWay, a bank's own KHQR API, …) is one class implementing this, plus one
 * line in config/payments.php. Nothing on the till changes.
 *
 * Two kinds of provider fit this shape:
 *
 *  - **Verifying** ones (`verifies()` true) mint a QR per sale and can say
 *    whether it was paid. The till confirms the sale on its own.
 *  - **Static** ones only ever show the shop's fixed QR; a human confirms.
 *    Every provider may offer a static QR too — it is what the till falls
 *    back to when it is offline and cannot mint anything.
 */
interface QrGateway
{
    /** Stable key stored on charges and in settings, e.g. "bakong". */
    public function key(): string;

    public function label(): string;

    /** Whether this provider can mint per-sale QRs and confirm them itself. */
    public function verifies(): bool;

    /** Enough settings are filled in to take money. */
    public function isConfigured(): bool;

    /** The shop's fixed, reusable QR (no amount), or null if there is none. */
    public function staticQr(): ?string;

    /** Mint a QR for one sale. Only called when verifies() is true. */
    public function createCharge(ChargeRequest $request): IssuedCharge;

    /** Ask the provider whether this charge has been paid. */
    public function checkStatus(QrCharge $charge): StatusResult;

    /** Prove the credentials work, for the settings screen. Throws GatewayException if not. */
    public function testConnection(): string;
}
