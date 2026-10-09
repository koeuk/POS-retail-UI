<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetOtp;
use App\Services\PasswordOtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The send cooldown and the attempt counter both live in the limiter,
        // which the cache driver keeps between tests in this suite.
        RateLimiter::clear('password-otp-send:'.sha1('reset@pos.test'));
        RateLimiter::clear('password-otp-verify:'.sha1('reset@pos.test'));
    }

    private function staff(): User
    {
        return User::factory()->create(['email' => 'reset@pos.test']);
    }

    /** Drives the flow up to the point a code has been issued. */
    private function requestCode(User $user): string
    {
        $code = null;

        Notification::assertSentTo($user, PasswordResetOtp::class, function (PasswordResetOtp $notification) use (&$code) {
            $code = $notification->code;

            return true;
        });

        return $code;
    }

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertStatus(200);
    }

    public function test_a_code_is_emailed_and_the_flow_moves_to_the_code_screen(): void
    {
        Notification::fake();

        $user = $this->staff();

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect(route('password.otp'));

        $code = $this->requestCode($user);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    /**
     * The response must be identical for an address that does not exist, or
     * the endpoint becomes a way to enumerate staff emails.
     */
    public function test_an_unknown_address_is_answered_the_same_way(): void
    {
        Notification::fake();

        $this->post('/forgot-password', ['email' => 'nobody@pos.test'])
            ->assertRedirect(route('password.otp'))
            ->assertSessionHasNoErrors();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_code_screen_redirects_when_no_reset_is_in_progress(): void
    {
        $this->get('/verify-code')->assertRedirect(route('password.request'));
    }

    public function test_password_can_be_reset_with_a_valid_code(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->requestCode($user);

        $this->post('/verify-code', ['code' => $code])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('password.reset'));

        $this->get('/reset-password')->assertStatus(200);

        $this->post('/reset-password', [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));

        // Spent, so the same code cannot be replayed.
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_a_wrong_code_is_rejected(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->requestCode($user);

        $wrong = str_pad((string) ((((int) $code) + 1) % 1000000), 6, '0', STR_PAD_LEFT);

        $this->post('/verify-code', ['code' => $wrong])->assertSessionHasErrors('code');

        $this->get('/reset-password')->assertRedirect(route('password.request'));
    }

    public function test_an_expired_code_is_rejected(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->requestCode($user);

        $this->travel(PasswordOtpService::EXPIRES_IN_MINUTES + 1)->minutes();

        $this->post('/verify-code', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    /**
     * Guessing must not be cheap: once the attempts are spent the code is
     * destroyed, so waiting out the limiter does not resume the search.
     */
    public function test_the_code_is_destroyed_after_too_many_wrong_guesses(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);
        $code = $this->requestCode($user);

        for ($i = 0; $i < PasswordOtpService::MAX_ATTEMPTS; $i++) {
            $this->post('/verify-code', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post('/verify-code', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_the_reset_screen_cannot_be_reached_without_verifying(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);

        $this->get('/reset-password')->assertRedirect(route('password.request'));

        $this->post('/reset-password', [
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('password.request'));

        $this->assertFalse(Hash::check('new-password-123', $user->fresh()->password));
    }

    /** A second request retires the first code rather than adding to it. */
    public function test_resending_replaces_the_previous_code(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);
        $first = $this->requestCode($user);

        // The cooldown is deliberate; clear it to exercise the replacement.
        RateLimiter::clear('password-otp-send:'.sha1($user->email));
        $this->post('/verify-code/resend');

        $this->assertDatabaseCount('password_reset_tokens', 1);
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        $this->assertFalse(Hash::check($first, $stored->token));
    }

    public function test_a_second_code_cannot_be_requested_immediately(): void
    {
        Notification::fake();

        $user = $this->staff();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentToTimes($user, PasswordResetOtp::class, 1);

        $this->post('/verify-code/resend');

        Notification::assertSentToTimes($user, PasswordResetOtp::class, 1);
    }
}
