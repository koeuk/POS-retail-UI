<?php

use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PaymentController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\ShopController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/Appearance');
    })->name('appearance');

    // Shop-wide, so admin only — this changes what every cashier sees.
    Route::middleware('role:admin')->group(function () {
        Route::get('settings/shop', [ShopController::class, 'edit'])->name('shop.edit');
        Route::put('settings/shop', [ShopController::class, 'update'])->name('shop.update');

        // Where QR money lands — the most sensitive setting in the app.
        Route::get('settings/payments', [PaymentController::class, 'edit'])->name('payments.edit');
        Route::put('settings/payments', [PaymentController::class, 'update'])->name('payments.update');
        Route::post('settings/payments/test', [PaymentController::class, 'test'])->name('payments.test');
    });
});
