<?php

namespace App\Payments;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Every payment setting, read in one place.
 *
 * The KHQR identity (account, name, city) is shared by all providers — it is
 * who the shop *is* to a banking app — while credentials belong to one
 * provider each. Secrets are stored encrypted with the app key and never
 * leave the server: the settings screen only learns whether one is set.
 */
final class PaymentSettings
{
    public const PROVIDER = 'qr_provider';

    public const ACCOUNT_ID = 'khqr_account_id';

    public const MERCHANT_NAME = 'khqr_merchant_name';

    public const MERCHANT_CITY = 'khqr_merchant_city';

    public const MERCHANT_ID = 'khqr_merchant_id';

    public const ACQUIRING_BANK = 'khqr_acquiring_bank';

    public const MANUAL_CONFIRM = 'qr_manual_confirm';

    public const BAKONG_TOKEN = 'bakong_token';

    public const BAKONG_ENVIRONMENT = 'bakong_environment';

    public static function provider(): string
    {
        $key = Setting::get(self::PROVIDER, 'manual');

        // A provider removed from config falls back rather than breaking the till.
        return array_key_exists($key, config('payments.providers', [])) ? $key : 'manual';
    }

    public static function accountId(): ?string
    {
        return self::filled(self::ACCOUNT_ID);
    }

    public static function merchantName(): string
    {
        return self::filled(self::MERCHANT_NAME) ?? (string) Setting::get('receipt_header', config('app.name'));
    }

    public static function merchantCity(): string
    {
        return self::filled(self::MERCHANT_CITY) ?? 'Phnom Penh';
    }

    public static function merchantId(): ?string
    {
        return self::filled(self::MERCHANT_ID);
    }

    public static function acquiringBank(): ?string
    {
        return self::filled(self::ACQUIRING_BANK);
    }

    /**
     * Whether a cashier may mark a verifying QR as paid by hand when the
     * provider has not confirmed it. On by default — a slow bank must never
     * hold a customer at the counter — but a shop that has been burnt by a
     * fake payment screenshot can switch it off.
     */
    public static function allowsManualConfirm(): bool
    {
        return Setting::get(self::MANUAL_CONFIRM, '1') === '1';
    }

    public static function bakongEnvironment(): string
    {
        return Setting::get(self::BAKONG_ENVIRONMENT) === 'sandbox' ? 'sandbox' : 'production';
    }

    /** Settings screen first, env second. Never sent to a browser. */
    public static function bakongToken(): ?string
    {
        return self::secret(self::BAKONG_TOKEN) ?? (config('payments.bakong.token') ?: null);
    }

    public static function hasBakongToken(): bool
    {
        return self::bakongToken() !== null;
    }

    public static function putSecret(string $key, ?string $value): void
    {
        Setting::put($key, $value === null || $value === '' ? null : Crypt::encryptString($value));
    }

    private static function secret(string $key): ?string
    {
        $stored = Setting::get($key);

        if (! $stored) {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            // APP_KEY rotated since it was saved: treat as unset, so the
            // settings screen asks for it again instead of the till erroring.
            return null;
        }
    }

    private static function filled(string $key): ?string
    {
        $value = trim((string) Setting::get($key));

        return $value === '' ? null : $value;
    }
}
