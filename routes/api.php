<?php

use App\Http\Controllers\Api;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Token API (v1)
|--------------------------------------------------------------------------
| The same app through a different door: every endpoint sits behind the same
| `permission:*` gates as its web screen, resolved for the token's user. The
| bare `role` middleware enforces is_active on every request, so revoking a
| token OR deactivating its user shuts an integration out on its next call.
| See docs/roles-and-permissions.md.
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::post('auth/token', [Api\AuthController::class, 'issue'])
        ->middleware('throttle:10,1')->name('auth.token');

    Route::middleware(['auth:sanctum', 'role'])->group(function () {
        Route::delete('auth/token', [Api\AuthController::class, 'revoke'])->name('auth.revoke');
        Route::get('me', [Api\AuthController::class, 'me'])->name('me');

        Route::middleware('permission:products')->group(function () {
            Route::get('products', [Api\CatalogueController::class, 'products'])->name('products.index');
            Route::get('products/{product}', [Api\CatalogueController::class, 'product'])->name('products.show');
        });

        Route::get('categories', [Api\CatalogueController::class, 'categories'])
            ->middleware('permission:categories')->name('categories.index');

        Route::middleware('permission:customers')->group(function () {
            Route::get('customers', [Api\CustomerController::class, 'index'])->name('customers.index');
            Route::post('customers', [Api\CustomerController::class, 'store'])->name('customers.store');
        });

        Route::middleware('permission:orders')->group(function () {
            Route::get('orders', [Api\OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [Api\OrderController::class, 'show'])->name('orders.show');
        });

        Route::post('orders/sync', [Api\SyncController::class, 'sync'])
            ->middleware('permission:pos')->name('orders.sync');

        Route::middleware('permission:debts')->group(function () {
            Route::get('debts', [Api\DebtController::class, 'index'])->name('debts.index');
            Route::post('debts/{order}/settle', [Api\DebtController::class, 'settle'])->name('debts.settle');
        });

        Route::get('inventory', [Api\InventoryController::class, 'index'])
            ->middleware('permission:inventory')->name('inventory.index');

        Route::get('reports/summary', [Api\ReportController::class, 'summary'])
            ->middleware('permission:reports')->name('reports.summary');
    });
});
