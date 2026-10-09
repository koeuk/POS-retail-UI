<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncOrdersRequest;
use App\Services\OrderSyncService;
use Illuminate\Http\JsonResponse;

/**
 * The same offline-queue contract as /pos/data/orders/sync, reachable with a
 * token: identical request rules, identical per-order results, identical
 * idempotency on client_uuid. One contract, two doors.
 */
class SyncController extends Controller
{
    /**
     * Sync orders
     *
     * The till's own contract, over a token: up to 200 orders per batch,
     * idempotent on `client_uuid`, always 200 with one result per order.
     * Statuses: `created` (landed now), `already_synced` (a retry collapsed
     * into an earlier flush — stock moves once), `failed` (this order only;
     * fix and resend the same uuid). On a `debt`, payments are the deposit —
     * capped at the bill; `myself` moves stock but records no revenue.
     *
     * @group Selling
     *
     * @response {"synced_at": "2026-09-01T10:15:02+07:00", "results": [{"client_uuid": "3f6c...", "status": "created", "order_id": 42, "order_no": "S1-R1-260901-0007", "message": null}]}
     */
    public function sync(SyncOrdersRequest $request, OrderSyncService $sync): JsonResponse
    {
        $orders = $request->validated('orders');
        $boundStoreId = $request->user()->store_id;

        $orders = array_map(function (array $order) use ($boundStoreId) {
            // A cashier's token is pinned to their store exactly as their
            // session is; the service enforces the same rule again.
            $order['store_id'] = $boundStoreId ?: ($order['store_id'] ?? null);

            return $order;
        }, $orders);

        return response()->json([
            'synced_at' => now()->toIso8601String(),
            'results' => $sync->syncMany($orders, $request->user()),
        ]);
    }
}
