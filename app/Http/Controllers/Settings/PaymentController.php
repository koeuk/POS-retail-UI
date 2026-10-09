<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Payments\GatewayException;
use App\Payments\Gateways\BakongGateway;
use App\Payments\Khqr\KhqrPayload;
use App\Payments\PaymentSettings;
use App\Payments\QrGateways;
use App\Payments\QrImage;
use App\Support\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where QR money goes and how the till confirms it.
 *
 * Admin only, like the rest of the shop settings — and more so than most:
 * whoever can change the account ID can redirect every QR payment the shop
 * takes. Every save is written to the money audit log for that reason.
 */
class PaymentController extends Controller
{
    public function __construct(private readonly QrGateways $gateways) {}

    public function edit(): Response
    {
        $static = QrGateways::staticKhqr();

        return Inertia::render('settings/Payments', [
            'payments' => [
                'qr_provider' => PaymentSettings::provider(),
                'khqr_account_id' => PaymentSettings::accountId(),
                'khqr_merchant_name' => Setting::get(PaymentSettings::MERCHANT_NAME),
                'khqr_merchant_city' => Setting::get(PaymentSettings::MERCHANT_CITY),
                'khqr_merchant_id' => PaymentSettings::merchantId(),
                'khqr_acquiring_bank' => PaymentSettings::acquiringBank(),
                'qr_manual_confirm' => PaymentSettings::allowsManualConfirm(),
                'bakong_environment' => PaymentSettings::bakongEnvironment(),
                // Never the token itself — only whether one is on file.
                'bakong_token_set' => PaymentSettings::hasBakongToken(),
                'bakong_calls_today' => BakongGateway::callsToday(),
                'bakong_daily_limit' => BakongGateway::dailyLimit(),
                'bakong_token_from_env' => ! Setting::get(PaymentSettings::BAKONG_TOKEN) && config('payments.bakong.token'),
            ],
            'providers' => $this->gateways->options(),
            'defaults' => [
                'merchant_name' => PaymentSettings::merchantName(),
                'merchant_city' => PaymentSettings::merchantCity(),
            ],
            'preview_svg' => $static ? QrImage::svg($static) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $ascii = 'regex:/^[\x20-\x7E]*$/';

        $validator = validator($request->all(), [
            'qr_provider' => ['required', Rule::in(array_keys(config('payments.providers')))],
            'khqr_account_id' => ['nullable', 'string', 'max:32', 'regex:/^[A-Za-z0-9._-]+@[A-Za-z0-9._-]+$/'],
            'khqr_merchant_name' => ['nullable', 'string', 'max:'.KhqrPayload::NAME_MAX, $ascii],
            'khqr_merchant_city' => ['nullable', 'string', 'max:'.KhqrPayload::CITY_MAX, $ascii],
            'khqr_merchant_id' => ['nullable', 'string', 'max:32', $ascii],
            'khqr_acquiring_bank' => ['nullable', 'required_with:khqr_merchant_id', 'string', 'max:32', $ascii],
            'qr_manual_confirm' => ['boolean'],
            'bakong_environment' => ['required', Rule::in(['production', 'sandbox'])],
            // Blank means "keep what is saved" — the field never shows the old token.
            'bakong_token' => ['nullable', 'string', 'max:4096'],
            'clear_bakong_token' => ['boolean'],
        ], [
            'khqr_account_id.regex' => 'A Bakong account ID looks like shopname@bank.',
            'khqr_merchant_name.regex' => 'Use English letters — banking apps cannot show Khmer script in this field.',
            'khqr_merchant_city.regex' => 'Use English letters — banking apps cannot show Khmer script in this field.',
        ]);

        // Choosing a provider that cannot take money yet would leave the till
        // offering a QR button that fails at the worst moment.
        $validator->after(function (Validator $v) use ($request) {
            if (! $request->filled('khqr_account_id') && $request->input('qr_provider') !== 'manual') {
                $v->errors()->add('khqr_account_id', 'This provider needs the shop\'s Bakong account ID.');
            }

            $tokenAfterSave = $request->boolean('clear_bakong_token')
                ? $request->filled('bakong_token') || config('payments.bakong.token')
                : $request->filled('bakong_token') || PaymentSettings::hasBakongToken();

            if ($request->input('qr_provider') === 'bakong' && ! $tokenAfterSave) {
                $v->errors()->add('bakong_token', 'Paste the token Bakong emailed you after registering.');
            }
        });

        $data = $validator->validate();

        try {
            $before = $this->auditable();

            DB::transaction(function () use ($data) {
                foreach ([
                    PaymentSettings::PROVIDER, PaymentSettings::ACCOUNT_ID, PaymentSettings::MERCHANT_NAME,
                    PaymentSettings::MERCHANT_CITY, PaymentSettings::MERCHANT_ID, PaymentSettings::ACQUIRING_BANK,
                    PaymentSettings::BAKONG_ENVIRONMENT,
                ] as $key) {
                    $value = trim((string) ($data[$key] ?? ''));
                    Setting::put($key, $value === '' ? null : $value);
                }

                Setting::put(PaymentSettings::MANUAL_CONFIRM, ($data['qr_manual_confirm'] ?? true) ? '1' : '0');

                if (! empty($data['clear_bakong_token'])) {
                    PaymentSettings::putSecret(PaymentSettings::BAKONG_TOKEN, null);
                }

                if (! empty($data['bakong_token'])) {
                    PaymentSettings::putSecret(PaymentSettings::BAKONG_TOKEN, trim($data['bakong_token']));
                }
            });

            $after = $this->auditable();
            $changed = array_keys(array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

            if ($changed) {
                AuditLog::money('Payment settings changed', null, [
                    'changed' => $changed,
                    'before' => array_intersect_key($before, array_flip($changed)),
                    'after' => array_intersect_key($after, array_flip($changed)),
                ], 'payment_settings');
            }

            return back()->with('success', 'Payment settings saved.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The payment settings could not be saved. Nothing was changed — try again.');
        }
    }

    /** Checks the saved settings, not the unsaved form: save first, then test. */
    public function test(): RedirectResponse
    {
        try {
            $message = $this->gateways->current()->testConnection();
        } catch (GatewayException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $message);
    }

    /**
     * What the audit entry compares. The token is reduced to "set or not" —
     * a secret must never be copied into a log that managers can read.
     *
     * @return array<string, string|bool|null>
     */
    private function auditable(): array
    {
        return [
            'provider' => PaymentSettings::provider(),
            'account_id' => PaymentSettings::accountId(),
            'merchant_name' => Setting::get(PaymentSettings::MERCHANT_NAME),
            'merchant_city' => Setting::get(PaymentSettings::MERCHANT_CITY),
            'merchant_id' => PaymentSettings::merchantId(),
            'acquiring_bank' => PaymentSettings::acquiringBank(),
            'manual_confirm' => PaymentSettings::allowsManualConfirm() ? 'on' : 'off',
            'bakong_environment' => PaymentSettings::bakongEnvironment(),
            // Ciphertext changes on every save, so a replaced token shows up
            // as a changed fingerprint without revealing anything about it.
            'bakong_token' => ($stored = Setting::get(PaymentSettings::BAKONG_TOKEN))
                ? 'set #'.substr(hash('sha256', $stored), 0, 8)
                : 'not set',
        ];
    }
}
