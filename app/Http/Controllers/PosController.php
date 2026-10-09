<?php

namespace App\Http\Controllers;

use App\Models\Store;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Serves the POS shell exactly once.
 *
 * Everything after this first render happens over axios against
 * /pos/data/*. Inertia must never be used to navigate inside /pos — a
 * redirect response cannot be queued in Dexie and replayed later, so a
 * single router.post() would silently break offline selling.
 */
class PosController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $storeId = $user->store_id ?: Store::query()->orderBy('id')->value('id');

        return Inertia::render('Pos/Index', [
            'boot' => [
                'store_id' => $storeId,
                'store_name' => Store::whereKey($storeId)->value('name'),
                'cashier_id' => $user->id,
                'cashier_name' => $user->name,
            ],
        ]);
    }
}
