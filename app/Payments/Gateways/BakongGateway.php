<?php

namespace App\Payments\Gateways;

use App\Enums\QrChargeStatus;
use App\Models\QrCharge;
use App\Payments\ChargeRequest;
use App\Payments\GatewayException;
use App\Payments\IssuedCharge;
use App\Payments\Khqr\KhqrPayload;
use App\Payments\PaymentSettings;
use App\Payments\QrGateway;
use App\Payments\QrGateways;
use App\Payments\StatusResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * National Bank of Cambodia's Bakong Open API.
 *
 * Bakong does not mint QRs — the shop does, locally, as a KHQR string with
 * the amount inside. What Bakong offers is the other half: given the MD5 of
 * that string, it says whether someone has paid it. So a charge costs no
 * network call to create, and "is it paid?" is one POST.
 *
 * There is no webhook on this API. The till polls while the QR is on screen,
 * and `payments:reconcile-qr` sweeps up anything paid after it looked away.
 */
class BakongGateway implements QrGateway
{
    public function key(): string
    {
        return 'bakong';
    }

    public function label(): string
    {
        return 'Bakong KHQR (auto-confirm)';
    }

    public function verifies(): bool
    {
        return true;
    }

    public function isConfigured(): bool
    {
        return PaymentSettings::accountId() !== null && PaymentSettings::hasBakongToken();
    }

    public function staticQr(): ?string
    {
        return QrGateways::staticKhqr();
    }

    public function createCharge(ChargeRequest $request): IssuedCharge
    {
        if (! $this->isConfigured()) {
            throw new GatewayException('Bakong is not set up yet — add the account ID and token in Settings → Payments.');
        }

        $payload = new KhqrPayload(
            accountId: PaymentSettings::accountId(),
            merchantName: PaymentSettings::merchantName(),
            merchantCity: PaymentSettings::merchantCity(),
            currency: $request->currency,
            amount: $request->amount,
            billNumber: $request->billNumber,
            storeLabel: $request->storeLabel,
            terminalLabel: $request->terminalLabel,
            merchantId: PaymentSettings::merchantId(),
            acquiringBank: PaymentSettings::acquiringBank(),
            expiresAtMs: $request->expiresAt->getTimestampMs(),
        );

        $qr = $payload->toString();

        return new IssuedCharge(qr: $qr, reference: md5($qr));
    }

    public function checkStatus(QrCharge $charge): StatusResult
    {
        $response = $this->post('/v1/check_transaction_by_md5', ['md5' => $charge->reference]);
        $body = $response->json() ?? [];

        // responseCode 0 with data is the only "paid". Anything else — most
        // often errorCode 1, "not found" — just means nobody has paid yet.
        if (($body['responseCode'] ?? null) === 0 && ! empty($body['data'])) {
            return $this->paidResult($charge, $body['data'], $body);
        }

        if (($body['errorCode'] ?? null) === 3) {
            return new StatusResult(QrChargeStatus::Failed, message: $body['responseMessage'] ?? 'Bakong reported the payment failed.', raw: $body);
        }

        return StatusResult::pending();
    }

    public function testConnection(): string
    {
        if (! PaymentSettings::accountId()) {
            throw new GatewayException('Enter the shop\'s Bakong account ID first.');
        }

        if (! PaymentSettings::hasBakongToken()) {
            throw new GatewayException('Paste the token Bakong emailed you after registering first.');
        }

        // Ask about a transaction that cannot exist: a working token gets a
        // polite "not found", a bad one gets refused.
        $this->post('/v1/check_transaction_by_md5', ['md5' => md5(Str::uuid()->toString())]);

        return 'Connected to Bakong ('.PaymentSettings::bakongEnvironment().'). The token is accepted.';
    }

    /**
     * Bakong found a payment for this hash. The hash already ties it to our
     * exact QR, but the amount and the receiving account are checked anyway:
     * a charge is marked paid only when the money matches what was asked.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $body
     */
    private function paidResult(QrCharge $charge, array $data, array $body): StatusResult
    {
        $amountMatches = abs((float) ($data['amount'] ?? 0) - (float) $charge->amount) < 0.005;
        $currencyMatches = strtoupper((string) ($data['currency'] ?? $charge->currency)) === $charge->currency;
        $to = $data['toAccountId'] ?? null;
        $accountMatches = $to === null || strcasecmp($to, (string) PaymentSettings::accountId()) === 0;

        if (! ($amountMatches && $currencyMatches && $accountMatches)) {
            return new StatusResult(
                QrChargeStatus::Failed,
                providerRef: $data['hash'] ?? null,
                payer: $data['fromAccountId'] ?? null,
                message: 'Bakong has a payment for this QR, but the amount or account does not match. Check the shop account before handing over goods.',
                raw: $body,
            );
        }

        return new StatusResult(
            QrChargeStatus::Paid,
            providerRef: $data['hash'] ?? null,
            payer: $data['fromAccountId'] ?? null,
            raw: $body,
        );
    }

    /**
     * Calls made to Bakong today, counted on the shop's calendar. The free
     * tier allows 100 a day; running out mid-afternoon would silently turn
     * every QR sale into a hand confirmation, so the count is kept here and
     * shown on the settings screen.
     */
    public static function callsToday(): int
    {
        return (int) Cache::get(self::budgetKey(), 0);
    }

    public static function dailyLimit(): int
    {
        return (int) config('payments.bakong.daily_limit', 100);
    }

    private static function budgetKey(): string
    {
        return 'bakong:calls:'.now()->setTimezone(config('pos.business_timezone'))->format('Y-m-d');
    }

    /** @param array<string, mixed> $data */
    private function post(string $path, array $data): Response
    {
        if (self::callsToday() >= self::dailyLimit()) {
            throw new GatewayException('Today\'s Bakong check limit is used up — confirm QR payments by hand until tomorrow.');
        }

        Cache::add(self::budgetKey(), 0, now()->addDays(2));
        Cache::increment(self::budgetKey());

        try {
            $response = $this->client()->post($path, $data);
        } catch (ConnectionException) {
            throw new GatewayException('Cannot reach Bakong right now.');
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new GatewayException('Bakong refused the token — it has probably expired. Use "Renew" on the Bakong portal, then paste the new token in Settings → Payments.');
        }

        if ($response->serverError()) {
            throw new GatewayException('Bakong is having trouble (HTTP '.$response->status().'). Try again shortly.');
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        $base = config('payments.bakong.endpoints.'.PaymentSettings::bakongEnvironment());

        $key = (string) PaymentSettings::bakongToken();

        // The portal emails a JWT sent as a Bearer token; its newer screens
        // also mention "bkv2_…" keys sent as X-API-Key. Sending both lets
        // either work; the unused one is ignored.
        return Http::baseUrl($base)
            ->withToken($key)
            ->withHeaders(['X-API-Key' => $key])
            ->acceptJson()
            ->asJson()
            ->timeout(config('payments.bakong.timeout', 8))
            ->connectTimeout(4);
    }
}
