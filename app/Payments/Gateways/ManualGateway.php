<?php

namespace App\Payments\Gateways;

use App\Models\QrCharge;
use App\Payments\ChargeRequest;
use App\Payments\GatewayException;
use App\Payments\IssuedCharge;
use App\Payments\PaymentSettings;
use App\Payments\QrGateway;
use App\Payments\QrGateways;
use App\Payments\StatusResult;

/**
 * The shop's printed KHQR, on screen. The customer keys the amount into their
 * banking app and the cashier confirms after seeing the payment arrive on the
 * shop phone — exactly how most Cambodian shops take QR today, and the only
 * thing possible with no internet.
 */
class ManualGateway implements QrGateway
{
    public function key(): string
    {
        return 'manual';
    }

    public function label(): string
    {
        return 'Static KHQR (cashier confirms)';
    }

    public function verifies(): bool
    {
        return false;
    }

    public function isConfigured(): bool
    {
        return PaymentSettings::accountId() !== null;
    }

    public function staticQr(): ?string
    {
        return QrGateways::staticKhqr();
    }

    public function createCharge(ChargeRequest $request): IssuedCharge
    {
        throw new GatewayException('The static QR has no per-sale charges.');
    }

    public function checkStatus(QrCharge $charge): StatusResult
    {
        return StatusResult::pending();
    }

    public function testConnection(): string
    {
        if (! $this->isConfigured()) {
            throw new GatewayException('Enter the shop\'s Bakong account ID first.');
        }

        return 'The static QR is ready. Scan it with a banking app to check the name shown.';
    }
}
