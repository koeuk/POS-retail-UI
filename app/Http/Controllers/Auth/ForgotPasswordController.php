<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordOtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    public function __construct(private readonly PasswordOtpService $otp) {}

    /** Step 1: ask which account. */
    public function create(Request $request): Response
    {
        return Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Mail a code and move on to the code screen.
     *
     * The redirect happens whether or not the address belongs to anyone — the
     * response must not distinguish a real staff account from a typo.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $sent = $this->otp->send($validated['email']);

        $request->session()->put('password_otp.email', mb_strtolower(trim($validated['email'])));

        return to_route('password.otp')->with(
            'status',
            $sent
                ? __('If that account exists, a code is on its way.')
                : __('A code was sent recently. Check your inbox before asking for another.'),
        );
    }
}
