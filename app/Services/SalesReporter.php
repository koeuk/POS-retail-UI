<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read models for the dashboard and reports.
 *
 * Two rules run through everything here:
 *
 *  1. **Bucket by business day, not row age.** An offline batch can reach the
 *     server days after the sale. Grouping on created_at would credit Tuesday's
 *     takings to Thursday and make every daily figure wrong.
 *  2. **Only completed orders count.** Refunded and void orders stay in the
 *     table for audit, and must never inflate a sales total.
 *
 * The figures are built on the query builder, not Eloquent: a report is a
 * handful of aggregates, and hydrating models to read a SUM() is work for
 * nothing. The three dashboard lists that carry relations (low stock,
 * oversold, recent orders) stay on Eloquent because that is what relations
 * are for.
 */
class SalesReporter
{
    public function __construct(private readonly ?int $storeId = null) {}

    /**
     * The shop's calendar day for a sale.
     *
     * Both timestamps are UTC. The shop is not: a sale at 06:00 in Phnom Penh
     * is 23:00 UTC the day before, and reporting it under yesterday makes the
     * morning's takings disappear from today's dashboard. The stored instant
     * is therefore shifted into the business timezone before the date is taken.
     *
     * Returned as SQL text so callers that still concatenate it into a raw
     * clause can; inside this class it is wrapped as an Expression and handed
     * to the builder's own where/group/order methods.
     */
    public static function businessDay(): string
    {
        return 'DATE('.self::businessMoment().')';
    }

    public static function businessMoment(): string
    {
        $offset = self::utcOffset();

        $moment = 'COALESCE(orders.created_offline_at, orders.created_at)';

        // A zero offset means the shop keeps UTC; skip the conversion so the
        // expression stays index-friendly and readable in a query log.
        return $offset === '+00:00'
            ? $moment
            : sprintf("CONVERT_TZ(%s, '+00:00', '%s')", $moment, $offset);
    }

    /**
     * The business timezone as a fixed +HH:MM offset.
     *
     * CONVERT_TZ only accepts named zones when MySQL's timezone tables have
     * been loaded, which is not a safe assumption on a shop's own server — an
     * offset always works. Cambodia has no daylight saving, so a fixed offset
     * loses nothing; a zone that did observe it would need those tables.
     */
    public static function utcOffset(): string
    {
        return Carbon::now(config('pos.business_timezone'))->format('P');
    }

    /** "Now", as the shop reckons it. */
    public static function businessNow(): Carbon
    {
        return Carbon::now(config('pos.business_timezone'));
    }

    /** Admins see every store; everyone else is pinned to their own. */
    public static function for(User $user): self
    {
        return new self($user->isAdmin() ? null : $user->store_id);
    }

    /** What a report shows when there is nothing — or when it could not be read. */
    public static function emptyTotals(): array
    {
        return ['orders' => 0, 'sales' => '0.00', 'items' => 0, 'basket' => '0.00'];
    }

    /* ------------------------------------------------------------------ */
    /* Building blocks */
    /* ------------------------------------------------------------------ */

    /**
     * Only sales that are actually revenue. Owner consumption leaves the shelf
     * but never the till, so it is excluded from every figure here.
     *
     * Columns are table-qualified because the joins below bring in tables of
     * their own, and an unqualified `status` would become ambiguous the day
     * one of them grows such a column.
     */
    private function orders(): Query
    {
        return DB::table('orders')
            ->where('orders.status', OrderStatus::Completed->value)
            ->where('orders.sale_type', '!=', SaleType::Myself->value)
            ->when($this->storeId, fn (Query $q, int $id) => $q->where('orders.store_id', $id));
    }

    /** The lines of those orders. */
    private function lines(): Query
    {
        return $this->orders()->join('order_items', 'order_items.order_id', '=', 'orders.id');
    }

    /** The money taken against those orders. */
    private function payments(): Query
    {
        return $this->orders()->join('payments', 'payments.order_id', '=', 'orders.id');
    }

    /** The same revenue rule, for the Eloquent lists that need relations. */
    private function revenueOrders(): EloquentBuilder
    {
        return Order::query()
            ->where('status', OrderStatus::Completed)
            ->where('sale_type', '!=', SaleType::Myself->value)
            ->when($this->storeId, fn (EloquentBuilder $q, int $id) => $q->where('store_id', $id));
    }

    /** The business day, as an expression the builder's own where/group/order methods accept. */
    public static function day(): Expression
    {
        return DB::raw(self::businessDay());
    }

    public static function moment(): Expression
    {
        return DB::raw(self::businessMoment());
    }

    private function onDay(Query $query, Carbon $day): Query
    {
        return $query->where(self::day(), $day->toDateString());
    }

    private function between(Query $query, Carbon $from, Carbon $to): Query
    {
        return $query->whereBetween(self::day(), [$from->toDateString(), $to->toDateString()]);
    }

    /**
     * Orders, takings, items and average basket for whatever window `$orders`
     * and `$lines` are already scoped to. Three small aggregate queries rather
     * than one hand-written SELECT — the builder knows how to COUNT and SUM,
     * and NULL-on-empty is handled for us (sum() returns 0).
     *
     * @return array{orders: int, sales: string, items: int, basket: string}
     */
    private function totals(Query $orders, Query $lines): array
    {
        $count = (clone $orders)->count();
        $sales = (float) (clone $orders)->sum('orders.total');
        $items = (int) $lines->sum('order_items.qty');

        return [
            'orders' => $count,
            'sales' => number_format($sales, 2, '.', ''),
            'items' => $items,
            'basket' => number_format($count > 0 ? $sales / $count : 0, 2, '.', ''),
        ];
    }

    private static function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /* ------------------------------------------------------------------ */
    /* Dashboard */
    /* ------------------------------------------------------------------ */

    /** @return array{sales: string, orders: int, basket: string, items: int} */
    public function summaryFor(Carbon $day): array
    {
        return $this->totals(
            $this->onDay($this->orders(), $day),
            $this->onDay($this->lines(), $day),
        );
    }

    /** Products at or below their threshold — what to reorder. */
    public function lowStock(int $limit = 8): Collection
    {
        return Stock::query()
            ->with(['product:id,name,unit', 'store:id,name'])
            ->whereNotNull('low_stock_threshold')
            ->whereColumn('qty', '<=', 'low_stock_threshold')
            ->where('qty', '>=', 0)
            ->when($this->storeId, fn ($q, $id) => $q->where('store_id', $id))
            ->orderBy('qty')
            ->limit($limit)
            ->get();
    }

    /**
     * Stock driven below zero by offline sales that synced after the shelf
     * was already empty. This is the reconciliation list — the deliberate
     * cost of never rejecting a completed sale.
     */
    public function oversold(int $limit = 8): Collection
    {
        return Stock::query()
            ->with(['product:id,name,unit', 'store:id,name'])
            ->where('qty', '<', 0)
            ->when($this->storeId, fn ($q, $id) => $q->where('store_id', $id))
            ->orderBy('qty')
            ->limit($limit)
            ->get();
    }

    public function recentOrders(int $limit = 8): Collection
    {
        return $this->revenueOrders()
            ->with(['cashier:id,name', 'store:id,name'])
            ->orderByDesc(self::moment())
            ->limit($limit)
            ->get(['id', 'order_no', 'total', 'created_at', 'created_offline_at', 'cashier_id', 'store_id']);
    }

    /** Sales queued offline and not yet reconciled anywhere. */
    public function offlineOrdersToday(Carbon $day): int
    {
        return $this->onDay($this->orders(), $day)
            ->whereNotNull('orders.created_offline_at')
            ->count();
    }

    /* ------------------------------------------------------------------ */
    /* Reports */
    /* ------------------------------------------------------------------ */

    /** @return Collection<int, array{day: string, orders: int, sales: string}> */
    public function salesByDay(Carbon $from, Carbon $to): Collection
    {
        $rows = $this->between($this->orders(), $from, $to)
            ->select([
                DB::raw(self::businessDay().' as day'),
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(orders.total) as sales'),
            ])
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        // Fill the gaps so a quiet day reads as zero rather than vanishing and
        // making the chart lie about its own time axis.
        $out = collect();

        for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);

            $out->push([
                'day' => $key,
                'orders' => (int) ($row->orders ?? 0),
                'sales' => self::money($row->sales ?? 0),
            ]);
        }

        return $out;
    }

    public function salesByProduct(Carbon $from, Carbon $to, int $limit = 20): Collection
    {
        return $this->between($this->lines(), $from, $to)
            // Group on the snapshot name: what the customer was actually sold,
            // even if the product has been renamed since.
            ->select([
                'order_items.product_name',
                DB::raw('SUM(order_items.qty) as qty'),
                DB::raw('SUM(order_items.subtotal) as revenue'),
            ])
            ->groupBy('order_items.product_name')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn (object $row) => [
                'product_name' => $row->product_name,
                'qty' => (int) $row->qty,
                'revenue' => self::money($row->revenue),
            ]);
    }

    /**
     * Line revenue per supplier, keyed by vendor id. A pack sells as its own
     * product row, so it is credited to its parent's vendor when it carries
     * none of its own.
     *
     * @return Collection<int, array{orders: int, qty: int, revenue: string}>
     */
    public function salesByVendor(Carbon $from, Carbon $to): Collection
    {
        $vendor = 'COALESCE(products.vendor_id, parents.vendor_id)';

        return $this->between($this->lines(), $from, $to)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('products as parents', 'parents.id', '=', 'products.parent_product_id')
            ->whereRaw("{$vendor} IS NOT NULL")
            ->select([
                DB::raw("{$vendor} as vendor_id"),
                DB::raw('COUNT(DISTINCT orders.id) as orders'),
                DB::raw('SUM(order_items.qty) as qty'),
                DB::raw('SUM(order_items.subtotal) as revenue'),
            ])
            ->groupBy(DB::raw($vendor))
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->vendor_id => [
                'orders' => (int) $row->orders,
                'qty' => (int) $row->qty,
                'revenue' => self::money($row->revenue),
            ]]);
    }

    /**
     * One supplier's sales per base product, keyed by product id — packs roll
     * up into the product they are a pack of.
     *
     * @return Collection<int, array{qty: int, revenue: string}>
     */
    public function vendorProductSales(int $vendorId, Carbon $from, Carbon $to): Collection
    {
        $base = 'COALESCE(products.parent_product_id, products.id)';

        return $this->between($this->lines(), $from, $to)
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->leftJoin('products as parents', 'parents.id', '=', 'products.parent_product_id')
            ->whereRaw('COALESCE(products.vendor_id, parents.vendor_id) = ?', [$vendorId])
            ->select([
                DB::raw("{$base} as product_id"),
                // Base units, so a case of 24 counts as 24 cans sold.
                DB::raw('SUM(order_items.qty * COALESCE(products.units_per_pack, 1)) as qty'),
                DB::raw('SUM(order_items.subtotal) as revenue'),
            ])
            ->groupBy(DB::raw($base))
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->product_id => [
                'qty' => (int) $row->qty,
                'revenue' => self::money($row->revenue),
            ]]);
    }

    public function paymentBreakdown(Carbon $from, Carbon $to): Collection
    {
        return $this->between($this->payments(), $from, $to)
            ->select([
                'payments.method',
                DB::raw('COUNT(*) as count'),
                DB::raw('SUM(payments.amount) as amount'),
            ])
            ->groupBy('payments.method')
            ->orderByDesc('amount')
            ->get()
            ->map(fn (object $row) => [
                'method' => $row->method,
                'count' => (int) $row->count,
                'amount' => self::money($row->amount),
            ]);
    }

    /**
     * Money out on credit and not yet collected — the receivable book.
     * Not revenue timing: the sale already counted; this is who still owes.
     *
     * @return array{count: int, owed: string}
     */
    public function outstandingDebts(): array
    {
        $debts = DB::table('orders')
            ->where('orders.status', OrderStatus::Completed->value)
            ->where('orders.sale_type', SaleType::Debt->value)
            ->whereColumn('paid_amount', '<', 'total')
            ->when($this->storeId, fn (Query $q, int $id) => $q->where('orders.store_id', $id));

        return [
            'count' => (clone $debts)->count(),
            'owed' => self::money($debts->sum(DB::raw('total - paid_amount'))),
        ];
    }

    /**
     * What the owner took for themselves, at shelf price — week, month, year.
     * Kept out of every revenue figure; this is the habit's own scoreboard.
     *
     * @return array<string, array{count: int, value: string}>
     */
    public function myselfSpent(): array
    {
        $taken = function (Carbon $since): array {
            $q = DB::table('orders')
                ->where('orders.status', OrderStatus::Completed->value)
                ->where('orders.sale_type', SaleType::Myself->value)
                ->when($this->storeId, fn (Query $query, int $id) => $query->where('orders.store_id', $id))
                ->where(self::day(), '>=', $since->toDateString());

            return [
                'count' => (clone $q)->count(),
                'value' => self::money($q->sum('orders.total')),
            ];
        };

        $now = self::businessNow();

        return [
            'week' => $taken($now->copy()->startOfWeek()),
            'month' => $taken($now->copy()->startOfMonth()),
            'year' => $taken($now->copy()->startOfYear()),
        ];
    }

    /** @return array{orders: int, sales: string, items: int, basket: string} */
    public function rangeTotals(Carbon $from, Carbon $to): array
    {
        return $this->totals(
            $this->between($this->orders(), $from, $to),
            $this->between($this->lines(), $from, $to),
        );
    }
}
