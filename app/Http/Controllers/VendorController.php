<?php

namespace App\Http\Controllers;

use App\Http\Requests\VendorRequest;
use App\Models\Product;
use App\Models\Vendor;
use App\Services\SalesReporter;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class VendorController extends Controller
{
    /** The summary windows on offer, in days. */
    private const WINDOWS = [7, 30, 90];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vendor::class);

        [$days, $from, $to] = $this->window($request);
        $sales = SalesReporter::for($request->user())->salesByVendor($from, $to);

        $vendors = QueryBuilder::for(Vendor::class)
            // Base products only: a pack is a way of selling one, not another.
            ->withCount(['products' => fn (Builder $q) => $q->whereNull('parent_product_id'), 'users'])
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(function (Builder $q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('contact_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%");
                    });
                }),
            ])
            ->orderBy('name')
            ->paginate(PerPage::resolve($request))
            ->withQueryString()
            ->through(fn (Vendor $v) => array_merge($v->toArray(), [
                'sales' => $sales[$v->id] ?? ['orders' => 0, 'qty' => 0, 'revenue' => '0.00'],
            ]));

        return Inertia::render('Vendors/Index', [
            'vendors' => $vendors,
            'summary' => [
                'vendors' => Vendor::count(),
                'active' => Vendor::where('is_active', true)->count(),
                'products' => Product::whereNotNull('vendor_id')->whereNull('parent_product_id')->count(),
                'revenue' => number_format((float) $sales->sum(fn ($s) => (float) $s['revenue']), 2, '.', ''),
            ],
            'days' => $days,
            'windows' => self::WINDOWS,
            'filters' => ['search' => (string) $request->input('filter.search', '')],
        ]);
    }

    /** One supplier at a glance: what it supplies, what is left, what sold. */
    public function show(Request $request, Vendor $vendor): Response
    {
        $this->authorize('view', $vendor);

        [$days, $from, $to] = $this->window($request);
        $sales = SalesReporter::for($request->user())->vendorProductSales($vendor->id, $from, $to);

        $products = $vendor->products()
            ->whereNull('parent_product_id')
            ->withSum('stocks as on_hand', 'qty')
            ->orderBy('name')
            ->get(['id', 'uuid', 'vendor_id', 'name', 'sku', 'unit', 'cost_price', 'sell_price', 'is_active'])
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'name' => $p->name,
                'sku' => $p->sku,
                'unit' => $p->unit,
                'cost_price' => $p->cost_price,
                'sell_price' => $p->sell_price,
                'is_active' => $p->is_active,
                'on_hand' => (int) $p->on_hand,
                'sold' => $sales[$p->id]['qty'] ?? 0,
                'revenue' => $sales[$p->id]['revenue'] ?? '0.00',
            ]);

        return Inertia::render('Vendors/Show', [
            'vendor' => $vendor,
            'products' => $products,
            'users' => $vendor->users()->orderBy('name')->get(['id', 'uuid', 'name', 'email', 'role', 'is_active']),
            'totals' => [
                'products' => $products->count(),
                'on_hand' => $products->sum('on_hand'),
                // What the shelf cost to fill — negative (oversold) stock is
                // a debt to the count, not value on the shelf.
                'stock_value' => number_format(
                    $products->sum(fn ($p) => max(0, $p['on_hand']) * (float) $p['cost_price']), 2, '.', ''),
                'sold' => $products->sum('sold'),
                'revenue' => number_format($sales->sum(fn ($s) => (float) $s['revenue']), 2, '.', ''),
            ],
            'days' => $days,
            'windows' => self::WINDOWS,
        ]);
    }

    public function store(VendorRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Vendor::class);

            DB::transaction(fn () => Vendor::create($request->validated()));

            return back()->with('success', 'Vendor added.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(VendorRequest $request, Vendor $vendor): RedirectResponse
    {
        try {
            $this->authorize('update', $vendor);

            DB::transaction(fn () => $vendor->update($request->validated()));

            return back()->with('success', 'Vendor updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be saved. Nothing was changed — try again.');
        }
    }

    public function destroy(Vendor $vendor): RedirectResponse
    {
        try {
            $this->authorize('delete', $vendor);

            // Accounts sign in as this vendor — removing it would strand them.
            if ($vendor->users()->exists()) {
                return back()->withErrors([
                    'vendor' => 'This vendor still has staff accounts. Remove or reassign them first, or mark the vendor inactive.',
                ]);
            }

            $name = $vendor->name;
            // Its products stay on the shelf, just without a vendor.
            DB::transaction(fn () => $vendor->delete());

            return redirect()->route('vendors.index')->with('success', "{$name} was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The vendor could not be deleted. Nothing was changed — try again.');
        }
    }

    /** @return array{int, Carbon, Carbon} */
    private function window(Request $request): array
    {
        $days = (int) $request->input('days', 30);
        $days = in_array($days, self::WINDOWS, true) ? $days : 30;
        $to = SalesReporter::businessNow();

        return [$days, $to->copy()->subDays($days - 1)->startOfDay(), $to];
    }
}
