<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Order;
use App\Models\User;
use App\Services\SalesReporter;
use App\Support\Currency;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * What the owner took for themselves.
 *
 * These are not sales — they never touch the takings — but they are real
 * stock movements with a real cost, and a shop that does not know how much
 * it eats cannot tell why the shelf count and the bank balance disagree.
 * The figure shown is what the goods would have sold for.
 */
class ConsumptionController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'from' => (string) $request->input('filter.from', ''),
            'to' => (string) $request->input('filter.to', ''),
        ];

        $rows = QueryBuilder::for($this->scoped($user))
            ->with(['cashier:id,name'])
            ->with(['items' => fn ($q) => $q->select('id', 'order_id', 'product_name', 'qty')])
            ->allowedFilters(...[
                AllowedFilter::callback('search', fn (Builder $q, string $s) => $q
                    ->whereHas('items', fn ($i) => $i->where('product_name', 'like', "%{$s}%"))),
                AllowedFilter::callback('from', fn (Builder $q, string $from) => $q->businessDayFrom($from)),
                AllowedFilter::callback('to', fn (Builder $q, string $to) => $q->businessDayTo($to)),
            ])
            ->latestByBusinessMoment()
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return Inertia::render('Consumption/Index', [
            'rows' => $rows,
            'filters' => $filters,
            // Week, month and year, so the habit is visible at every scale —
            // the same figures the dashboard shows, from the same reporter.
            'summary' => SalesReporter::for($user)->myselfSpent(),
            'currency' => Currency::current()->toArray(),
        ]);
    }

    private function scoped(User $user): Builder
    {
        return Order::query()
            ->where('sale_type', SaleType::Myself->value)
            ->where('status', OrderStatus::Completed)
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
