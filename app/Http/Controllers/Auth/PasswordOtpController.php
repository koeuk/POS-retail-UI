<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PasswordOtpController extends Controller
{
    public function __construct(private readonly PasswordOtpService $otp) {}

    /** Step 2: enter the emailed code. */
    public function create(Request $request): Response|RedirectResponse
    {
        $email = $request->session()->get('password_otp.email');

        // Landing here cold — a bookmark, or a session that expired — has no
        // address to verify against, so start the flow rather than show an
        // empty box that can never succeed.
        if (! $email) {
            return to_route('password.request');
        }

        return Inertia::render('auth/VerifyOtp', [
            'email' => $email,
            'status' => $request->session()->get('status'),
            'length' => PasswordOtpService::LENGTH,
            'secondsUntilResend' => $this->otp->secondsUntilResend($email),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $email = $request->session()->get('password_otp.email');

        if (! $email) {
            return to_route('password.request');
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:'.PasswordOtpService::LENGTH],
        ]);

        if (! $this->otp->verify($email, $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => __('That code is wrong or has expired.'),
            ]);
        }

        /*
         * Verification is recorded server-side rather than handed back as a
         * ticket. The code never travels again after this point, so it cannot
         * be replayed out of the browser's history or a shared screenshot.
         */
        $request->session()->put('password_otp.verified_at', now()->timestamp);

        return to_route('password.reset');
    }

    /** Send another code, subject to the same cooldown. */
    public function resend(Request $request): RedirectResponse
    {
        $email = $request->session()->get('password_otp.email');

        if (! $email) {
            return to_route('password.request');
        }

        $sent = $this->otp->send($email);

        return back()->with(
            'status',
            $sent
                ? __('A new code is on its way.')
                : __('Please wait a moment before asking for another code.'),
        );
    }
}
