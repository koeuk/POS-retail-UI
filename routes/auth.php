<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\EmailVerificationPromptController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordOtpController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    /*
     * Public self-registration is deliberately disabled. This is staff
     * software: an anonymous signup would mint a cashier account with no
     * store binding. Staff accounts are created by an admin in Phase 3.
     *
     * Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
     * Route::post('register', [RegisteredUserController::class, 'store']);
     */

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    /*
     * Password reset is a three-step code flow, not a emailed link: ask for the
     * account, type the six digits that arrive, then choose a password. Which
     * address is being reset lives in the session throughout, so the code is
     * never carried in a URL where a proxy or a screenshot could keep it.
     */
    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])
        ->name('password.request');

    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');

    Route::get('verify-code', [PasswordOtpController::class, 'create'])
        ->name('password.otp');

    Route::post('verify-code', [PasswordOtpController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('password.otp.verify');

    Route::post('verify-code/resend', [PasswordOtpController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('password.otp.resend');

    Route::get('reset-password', [NewPasswordController::class, 'create'])
        ->name('password.reset');

    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::get('verify-email', EmailVerificationPromptController::class)
        ->name('verification.notice');

    Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::post('email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
