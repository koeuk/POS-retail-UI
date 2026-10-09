<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Store-wide sales history.
 *
 * Distinct from the receipt drawer inside /pos, which reads that one device's
 * IndexedDB and only knows about sales rung up on it. This reads the server,
 * so a manager can find any sale from any till — including one still sitting
 * unsynced on a tablet, which simply has not arrived here yet.
 */
class OrderController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'status' => (string) $request->input('filter.status', ''),
            'method' => (string) $request->input('filter.method', ''),
            'from' => (string) $request->input('filter.from', ''),
            'to' => (string) $request->input('filter.to', ''),
        ];

        $orders = QueryBuilder::for($this->scoped($user))
            ->with([
                'cashier:id,name',
                'store:id,name',
                'register:id,name',
                'customer:id,name',
                'payments:id,order_id,method,amount',
            ])
            ->withCount('items')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(function (Builder $q) use ($search) {
                        $q->where('order_no', 'like', "%{$search}%")
                            ->orWhereHas('cashier', fn ($c) => $c->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                    });
                }),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('method', fn (Builder $q, string $method) => $q->whereHas('payments', fn ($p) => $p->where('method', $method))),
                // Filter on the business day too — the day the sale happened,
                // not the day the row reached the server.
                AllowedFilter::callback('from', fn (Builder $q, string $from) => $q->businessDayFrom($from)),
                AllowedFilter::callback('to', fn (Builder $q, string $to) => $q->businessDayTo($to)),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return Inertia::render('Orders/Index', [
            'orders' => $orders,
            'filters' => $filters,
            'statuses' => collect(OrderStatus::cases())
                ->map(fn (OrderStatus $s) => ['value' => $s->value, 'label' => $s->label()]),
            'methods' => collect(PaymentMethod::cases())
                ->map(fn (PaymentMethod $m) => ['value' => $m->value, 'label' => $m->label()]),
            'stores' => $user->isAdmin() ? Store::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function show(Request $request, Order $order): Response
    {
        // A manager must not be able to read another store's takings by
        // guessing an id — scope the lookup rather than the response.
        $this->scoped($request->user())->whereKey($order->id)->firstOrFail();

        $order->load([
            'items',
            'payments',
            'cashier:id,name',
            'store:id,name,address,phone',
            'register:id,name',
            'customer:id,name,phone,email',
        ]);

        return Inertia::render('Orders/Show', [
            'order' => $order,
            // What is still owed on this sale. Non-zero only for a debt that
            // has not been paid off, which is exactly when the page should
            // say so — a settled debt reads like any other completed order.
            'outstanding' => $order->outstanding(),
            // Receipt header/footer come from settings so a reprint from here
            // matches what the till printed at the counter.
            'settings' => [
                'receipt_header' => Setting::get('receipt_header', config('app.name')),
                'receipt_footer' => Setting::get('receipt_footer'),
                'currency' => Currency::current()->toArray(),
            ],
        ]);
    }

    /** Admins see every store; everyone else is pinned to their own. */
    private function scoped(User $user): Builder
    {
        return Order::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
