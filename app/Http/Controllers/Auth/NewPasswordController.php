<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordOtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    public function __construct(private readonly PasswordOtpService $otp) {}

    /** Step 3: choose the new password. */
    public function create(Request $request): Response|RedirectResponse
    {
        if (! $email = $this->verifiedEmail($request)) {
            return to_route('password.request');
        }

        return Inertia::render('auth/ResetPassword', [
            'email' => $email,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (! $email = $this->verifiedEmail($request)) {
            return to_route('password.request');
        }

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::where('email', $email)->first();

        // The account can disappear between verifying the code and submitting
        // the form — an admin deleting staff mid-reset is the realistic case.
        if (! $user) {
            $this->clear($request, $email);

            throw ValidationException::withMessages([
                'password' => __('That account is no longer available.'),
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            // Rotating this signs out every "remember me" device the old
            // password left behind, which is the point of a reset.
            'remember_token' => Str::random(60),
        ])->save();

        event(new PasswordReset($user));

        $this->clear($request, $email);

        return to_route('login')->with('status', __('Your password has been reset. Sign in with it now.'));
    }

    /**
     * The address this session proved ownership of, or null. The code must
     * still exist: spending it elsewhere, or letting it age out, ends the run.
     */
    private function verifiedEmail(Request $request): ?string
    {
        $email = $request->session()->get('password_otp.email');
        $verifiedAt = $request->session()->get('password_otp.verified_at');

        if (! $email || ! $verifiedAt) {
            return null;
        }

        $expired = now()->timestamp - (int) $verifiedAt > PasswordOtpService::VERIFIED_WINDOW_MINUTES * 60;

        if ($expired || ! $this->otp->hasLiveCode($email)) {
            return null;
        }

        return $email;
    }

    private function clear(Request $request, string $email): void
    {
        $this->otp->forget($email);
        $request->session()->forget('password_otp');
    }
}
