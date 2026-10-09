<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One-time codes for password reset.
 *
 * Staff read mail on a phone, often inside Telegram's in-app browser, where a
 * long signed reset link is as likely to be mangled as followed. A six-digit
 * code can be retyped anywhere, so this replaces the link flow outright.
 *
 * Codes live in the stock `password_reset_tokens` table — same shape the link
 * flow used (email, token, created_at), with a hash of the code in `token`.
 */
class PasswordOtpService
{
    public const LENGTH = 6;

    public const EXPIRES_IN_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /** How long a verified code stays good for while the new password is typed. */
    public const VERIFIED_WINDOW_MINUTES = 10;

    private const TABLE = 'password_reset_tokens';

    /**
     * Issue a code and mail it.
     *
     * Returns false only when the caller is asking too often. A missing account
     * still returns true and still burns the cooldown: any other answer would
     * turn this endpoint into a check for which staff emails exist.
     */
    public function send(string $email): bool
    {
        $email = $this->normalise($email);

        if (RateLimiter::tooManyAttempts($this->sendKey($email), 1)) {
            return false;
        }

        RateLimiter::hit($this->sendKey($email), self::RESEND_COOLDOWN_SECONDS);

        $user = User::where('email', $email)->first();

        if (! $user) {
            return true;
        }

        $code = $this->generateCode();

        // One live code per address — issuing a second must retire the first,
        // or an attacker who triggers a resend doubles their guessing surface.
        DB::table(self::TABLE)->updateOrInsert(
            ['email' => $email],
            ['token' => Hash::make($code), 'created_at' => now()],
        );

        RateLimiter::clear($this->verifyKey($email));

        $user->notify(new PasswordResetOtp($code));

        return true;
    }

    /**
     * Check a submitted code. Wrong guesses are counted; running out of guesses
     * destroys the code, so a burnt code cannot be brute-forced by resetting
     * the limiter with a wait.
     */
    public function verify(string $email, string $code): bool
    {
        $email = $this->normalise($email);

        if (RateLimiter::tooManyAttempts($this->verifyKey($email), self::MAX_ATTEMPTS)) {
            $this->forget($email);

            return false;
        }

        $record = $this->record($email);

        if (! $record || ! Hash::check($code, $record->token)) {
            RateLimiter::hit($this->verifyKey($email), self::EXPIRES_IN_MINUTES * 60);

            return false;
        }

        RateLimiter::clear($this->verifyKey($email));

        return true;
    }

    /** True while an unexpired code exists for this address. */
    public function hasLiveCode(string $email): bool
    {
        return $this->record($this->normalise($email)) !== null;
    }

    /** Seconds until another code may be requested; 0 when one may be sent now. */
    public function secondsUntilResend(string $email): int
    {
        return RateLimiter::availableIn($this->sendKey($this->normalise($email)));
    }

    /** Spend the code. Called once the password has actually been changed. */
    public function forget(string $email): void
    {
        $email = $this->normalise($email);

        DB::table(self::TABLE)->where('email', $email)->delete();
        RateLimiter::clear($this->verifyKey($email));
    }

    /**
     * The live row for an address, or null when there is none or it has aged
     * out. Expired rows are deleted on sight rather than left to accumulate.
     */
    private function record(string $email): ?object
    {
        $record = DB::table(self::TABLE)->where('email', $email)->first();

        if (! $record) {
            return null;
        }

        if (Carbon::parse($record->created_at)->addMinutes(self::EXPIRES_IN_MINUTES)->isPast()) {
            DB::table(self::TABLE)->where('email', $email)->delete();

            return null;
        }

        return $record;
    }

    /** Zero-padded so every code is exactly LENGTH digits — "0042" reads wrong. */
    private function generateCode(): string
    {
        return str_pad((string) random_int(0, (10 ** self::LENGTH) - 1), self::LENGTH, '0', STR_PAD_LEFT);
    }

    private function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** Hashed so a raw staff address never lands in the cache keyspace. */
    private function sendKey(string $email): string
    {
        return 'password-otp-send:'.sha1($email);
    }

    private function verifyKey(string $email): string
    {
        return 'password-otp-verify:'.sha1($email);
    }
}
