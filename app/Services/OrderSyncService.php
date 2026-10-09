<?php

namespace App\Services;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\Product;
use App\Models\Register;
use App\Models\Setting;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Payments\QrPayments;
use App\Support\AuditLog;
use App\Support\Currency;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns queued offline sales into real orders.
 *
 * The contract this class has to honour, in order of importance:
 *
 *  1. **Retrying is free.** The same client_uuid may arrive any number of
 *     times, from any number of tabs, and must produce exactly one order with
 *     exactly one stock movement.
 *  2. **A completed sale is never rejected.** The cash is already in the
 *     drawer and the goods have left the shop. Insufficient stock is recorded,
 *     not refused — stocks.qty is signed for exactly this reason.
 *  3. **Stock only moves here.** Client-side stock maths is a display hint;
 *     this is the ledger.
 *  4. **One bad order does not sink the batch.** Every order is reported
 *     individually so a single malformed row cannot strand the other 49.
 */
class OrderSyncService
{
    /** Guards against an order_no collision looping forever. */
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly QrPayments $qrPayments) {}

    /**
     * @param  array<int, array<string, mixed>>  $orders
     * @return array<int, array<string, mixed>> one result per order, keyed by client_uuid
     */
    public function syncMany(array $orders, User $cashier): array
    {
        return array_map(fn (array $order) => $this->syncOne($order, $cashier), $orders);
    }

    /** @param array<string, mixed> $payload */
    public function syncOne(array $payload, User $cashier): array
    {
        $uuid = $payload['client_uuid'];

        // Fast path: already landed on a previous flush.
        if ($existing = Order::where('client_uuid', $uuid)->first()) {
            return $this->result($uuid, 'already_synced', $existing);
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $order = DB::transaction(fn () => $this->createOrder($payload, $cashier, $attempt));

                return $this->result($uuid, 'created', $order);
            } catch (UniqueConstraintViolationException $e) {
                // Either another flush won the race on client_uuid, or two
                // registers grabbed the same order_no. Only the first is done;
                // the next attempt asks for a number one further along.
                if ($winner = Order::where('client_uuid', $uuid)->first()) {
                    return $this->result($uuid, 'already_synced', $winner);
                }

                // order_no collision — take the next sequence number and retry.
                continue;
            } catch (Throwable $e) {
                Log::error('POS order sync failed', [
                    'client_uuid' => $uuid,
                    'cashier_id' => $cashier->id,
                    'message' => $e->getMessage(),
                ]);

                return $this->result($uuid, 'failed', null, $e->getMessage());
            }
        }

        return $this->result($uuid, 'failed', null, 'Could not allocate an order number.');
    }

    /** @param array<string, mixed> $payload */
    private function createOrder(array $payload, User $cashier, int $attempt = 1): Order
    {
        $storeId = $this->resolveStore($payload, $cashier);
        $items = $payload['items'];

        $totals = new OrderTotals($items, $payload['discount_amount'] ?? 0);

        $offlineAt = $this->offlineTimestamp($payload);

        $paid = array_sum(array_map(
            fn ($p) => OrderTotals::toMinor($p['amount']),
            $payload['payments'] ?? []
        ));

        // Change is only ever given against cash; card and QR are exact.
        $saleType = SaleType::tryFrom($payload['sale_type'] ?? '') ?? SaleType::Customer;

        $givesChange = collect($payload['payments'] ?? [])
            ->contains(fn ($p) => $p['method'] === 'cash');

        $change = $givesChange
            ? max(0, $paid - OrderTotals::toMinor($totals->total()))
            : 0;

        $order = Order::create([
            'client_uuid' => $payload['client_uuid'],
            'order_no' => $this->nextOrderNo($storeId, $payload['register_id'] ?? null, $offlineAt, $attempt),
            'store_id' => $storeId,
            'register_id' => $this->resolveRegister($payload, $storeId),
            'cashier_id' => $cashier->id,
            'customer_id' => $payload['customer_id'] ?? null,
            'sale_type' => $saleType,
            'currency' => Currency::current()->code,
            'subtotal' => $totals->subtotal(),
            'discount_amount' => $totals->discountAmount(),
            'total' => $totals->total(),
            // A debt may carry a deposit — money that really crossed the
            // counter — and the rest is owed. Capped at the bill because sync
            // never rejects a completed sale: a till that somehow sends more
            // simply produces a debt born settled, not a stuck queue.
            'paid_amount' => OrderTotals::toDecimal(
                $saleType->isReceivable() ? min($paid, OrderTotals::toMinor($totals->total())) : $paid
            ),
            'change_amount' => OrderTotals::toDecimal($saleType->isReceivable() ? 0 : $change),
            'status' => OrderStatus::Completed,
            'synced_at' => now(),
            'created_offline_at' => $offlineAt,
        ]);

        $products = Product::whereIn('id', array_column($items, 'product_id'))
            ->get()
            ->keyBy('id');

        foreach ($items as $index => $item) {
            $product = $products->get($item['product_id']);

            $order->items()->create([
                'product_id' => $item['product_id'],
                'product_variant_id' => null,

                // Snapshots. The name and price are whatever the customer was
                // shown at the till, not whatever the product says today.
                'product_name' => $item['product_name'] ?? $product?->name ?? 'Unknown product',
                'unit_price' => OrderTotals::toDecimal(OrderTotals::toMinor($item['unit_price'])),
                'qty' => (int) $item['qty'],
                'discount' => OrderTotals::toDecimal(OrderTotals::toMinor($item['discount'] ?? 0)),
                'subtotal' => $totals->lineSubtotal($index),
            ]);

            if ($product?->track_stock) {
                // A pack sells in base units: one case of 24 takes 24 off the
                // can's shelf, not one off a shelf of cases.
                $this->moveStock($product, $storeId, $product->baseUnits((int) $item['qty']), $order, $cashier);
            }
        }

        /*
         * What lands in the payments ledger. A deposit on a debt is real money
         * and gets its row — settle() sums this ledger into paid_amount, so
         * the row IS the deposit's paper trail. The "0.00 cash" a till sends
         * for an all-in-debt sale is not a payment and is skipped, and owner
         * take-outs move no money at all.
         */
        $paymentsToRecord = match (true) {
            ! $saleType->isRevenue() => [],
            $saleType->isReceivable() => array_values(array_filter(
                $payload['payments'] ?? [],
                fn (array $payment) => OrderTotals::toMinor($payment['amount'] ?? 0) > 0,
            )),
            default => $payload['payments'] ?? [],
        };

        foreach ($paymentsToRecord as $payment) {
            $order->payments()->create([
                'method' => $payment['method'],
                'amount' => OrderTotals::toDecimal(OrderTotals::toMinor($payment['amount'])),
                'reference_no' => $payment['reference_no'] ?? null,
            ]);

            // A QR payment carries its charge's reference, which is how the
            // bank's confirmation finds the sale it paid for.
            if ($payment['method'] === PaymentMethod::Qr->value) {
                $this->qrPayments->attachToOrder($order, $payment['reference_no'] ?? null);
            }
        }

        /*
         * The cash trail. Logged here rather than in the controllers because
         * every completed sale — till sync and the straight-to-debt form
         * alike — is created through this method, so one entry point covers
         * both and neither can be added without its audit row.
         */
        AuditLog::money("Sale {$order->order_no} recorded", $order, [
            'order_no' => $order->order_no,
            'sale_type' => $saleType->value,
            'total' => (string) $order->total,
            'paid_amount' => (string) $order->paid_amount,
            'currency' => $order->currency,
            'item_count' => count($items),
            'store_id' => $storeId,
            // Sales sync from a till that was offline; when the sale actually
            // happened matters more than when the server heard about it.
            'created_offline_at' => $offlineAt?->toIso8601String(),
        ], 'sale');

        return $order;
    }

    /**
     * Decrement stock and record why. `$qty` is in **base units**, already
     * multiplied out by the caller. The decrement is a single SQL statement so
     * two concurrent syncs cannot read-modify-write over each other, and qty is
     * allowed to go negative — see rule 2 in the class docblock.
     */
    private function moveStock(Product $product, int $storeId, int $qty, Order $order, User $cashier): void
    {
        // Stock hangs off the base product; a pack has no shelf of its own.
        $stockProductId = $product->stockProductId();

        $stock = Stock::firstOrCreate(
            ['product_id' => $stockProductId, 'store_id' => $storeId],
            ['qty' => 0],
        );

        $stock->decrement('qty', $qty);

        $order->store->inventoryLogs()->create([
            'product_id' => $stockProductId,
            'type' => InventoryLogType::Sale,
            'qty_change' => -$qty,
            'reference_type' => Order::class,
            'reference_id' => $order->id,
            'note' => null,
            'created_by' => $cashier->id,
        ]);
    }

    /**
     * The register this sale was rung up on, or null.
     *
     * A register belongs to one store. Recording a Store B terminal against a
     * Store A sale would make the order number and the receipt disagree about
     * where the sale happened, so an unrelated register is dropped rather than
     * stored — the sale itself is never at risk over it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveRegister(array $payload, int $storeId): ?int
    {
        $registerId = $payload['register_id'] ?? null;

        if (! $registerId) {
            return null;
        }

        return Register::where('id', $registerId)->where('store_id', $storeId)->exists()
            ? (int) $registerId
            : null;
    }

    /**
     * When the sale was actually rung up, according to the till — sanity
     * checked, and normalised to UTC so it can be compared with created_at.
     *
     * Two separate problems are handled here:
     *
     *  - **Clock skew.** A cheap tablet that has lost its battery comes back
     *    believing it is 2031, and that date would otherwise be written into
     *    the order number and every report. The sale is never rejected for it
     *    — the cash is in the drawer — so an implausible stamp falls back to
     *    now() rather than failing.
     *  - **Timezone.** Carbon::parse keeps whatever offset the device sent, and
     *    Eloquent then stores that wall-clock reading verbatim, while
     *    created_at is UTC. Two columns on two different clocks made
     *    COALESCE(created_offline_at, created_at) meaningless.
     *
     * @param  array<string, mixed>  $payload
     */
    private function offlineTimestamp(array $payload): ?Carbon
    {
        if (! isset($payload['created_offline_at'])) {
            return null;
        }

        try {
            $at = Carbon::parse($payload['created_offline_at'])->utc();
        } catch (Throwable) {
            return null;
        }

        // A day of slack forwards for ordinary drift; a year back covers any
        // real offline stretch. Anything outside that is a broken clock.
        $implausible = $at->isAfter(now()->addDay()) || $at->isBefore(now()->subYear());

        if ($implausible) {
            Log::warning('POS order carried an implausible timestamp; using server time', [
                'client_uuid' => $payload['client_uuid'] ?? null,
                'claimed' => $at->toIso8601String(),
            ]);

            return now();
        }

        return $at;
    }

    /**
     * Which store this sale belongs to.
     *
     * A cashier bound to a store may only ever write to that store, whatever
     * the payload says. The client is an offline device that has been out of
     * contact for hours — its idea of which shop it is standing in is a hint,
     * and honouring it lets one till move another shop's stock.
     *
     * Only an unbound user (an admin covering several shops) may name one.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveStore(array $payload, User $cashier): int
    {
        if ($cashier->store_id) {
            return $cashier->store_id;
        }

        return (int) ($payload['store_id'] ?? Store::query()->orderBy('id')->value('id'));
    }

    /**
     * Format: S{store}-R{register}-{YYMMDD}-{seq}
     *
     * Sequence is per store per business day, and the business day is when the
     * sale actually happened — not when it reached the server. An offline batch
     * from yesterday must number itself into yesterday's run.
     */
    private function nextOrderNo(int $storeId, ?int $registerId, ?Carbon $offlineAt, int $attempt = 1): string
    {
        // Numbered by the shop's day, matching how the reports group them.
        $day = ($offlineAt ?? now())->copy()->setTimezone(config('pos.business_timezone'));
        // The leading code is the shop's to choose (Settings -> Shop). The
        // default encodes store and register; a custom code is shared by every
        // till, so its daily sequence is simply shared too.
        $code = trim((string) Setting::get('order_prefix'));
        $prefix = ($code !== '' ? $code : sprintf('S%d-R%d', $storeId, $registerId ?? 0)).'-'.$day->format('ymd').'-';

        /*
         * Read the highest sequence already issued rather than counting rows.
         *
         * Counting looks equivalent and is not: delete one order from the
         * middle of a day and the count points at a number that already
         * exists, so every subsequent sale collides — and the retry recounts
         * the same number, so it can never get past it. Taking max()+1 steps
         * over gaps, and the attempt offset walks forward if two registers
         * land on the same number at once.
         */
        // Scoped by the prefix alone: the default form already names the
        // store and register, and a custom code must count across stores or
        // two shops would mint the same number on the same day.
        $highest = (int) Order::where('order_no', 'like', $prefix.'%')
            ->max(DB::raw("CAST(SUBSTRING_INDEX(order_no, '-', -1) AS UNSIGNED)"));

        return $prefix.sprintf('%04d', $highest + $attempt);
    }

    private function result(string $uuid, string $status, ?Order $order, ?string $message = null): array
    {
        return [
            'client_uuid' => $uuid,
            'status' => $status,
            'order_id' => $order?->id,
            'order_no' => $order?->order_no,
            'total' => $order?->total,
            'message' => $message,
        ];
    }
}
