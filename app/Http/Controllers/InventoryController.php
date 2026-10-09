<?php

namespace App\Http\Controllers;

use App\Enums\Action;
use App\Enums\InventoryLogType;
use App\Enums\Permission;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Stock management.
 *
 * Deliberately **not** raw CRUD on `stocks.qty`. Everything else in this build
 * treats `inventory_logs` as the ledger — a sale writes one, the dashboard's
 * oversold list is read against it, and the product screen states plainly that
 * stock moves only through sales and inventory movements. Letting someone type
 * a new number straight into the column would leave unexplained jumps that
 * nobody could reconcile a week later.
 *
 * So a quantity is never set directly; a **movement** is recorded and the
 * quantity follows. A stock-count correction still lets you land on any number
 * you like — it just works out the delta and writes down who changed it, when,
 * and why.
 */
class InventoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'store_id' => (string) $request->input('filter.store_id', ''),
            'state' => (string) $request->input('filter.state', ''),
            'sort' => (string) $request->input('sort', 'qty'),
        ];

        $stocks = QueryBuilder::for($this->scoped($user))
            ->with([
                'product:id,name,sku,barcode,unit,case_size,is_active',
                // Pack sizes let the page say "8 × 12 + 1" instead of a bare 97 —
                // the way the shelf is actually counted.
                'product.packs:id,parent_product_id,name,units_per_pack',
                'store:id,name',
            ])
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->whereHas('product', function (Builder $q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%");
                    });
                }),
                AllowedFilter::exact('store_id'),
                AllowedFilter::callback('state', fn (Builder $q, string $state) => match ($state) {
                    'low' => $q->whereNotNull('low_stock_threshold')
                        ->whereColumn('qty', '<=', 'low_stock_threshold')
                        ->where('qty', '>=', 0),
                    'oversold' => $q->where('qty', '<', 0),
                    'out' => $q->where('qty', '=', 0),
                    default => $q,
                }),
            ])
            ->allowedSorts(...[
                AllowedSort::field('qty'),
                // Sorting by product name means a subquery into products;
                // sorting by stock uses the row's own column and needs none.
                AllowedSort::callback('name', fn (Builder $q, bool $descending) => $q->orderBy(
                    Product::select('name')->whereColumn('products.id', 'stocks.product_id'),
                    $descending ? 'desc' : 'asc',
                )),
            ])
            // Lowest first by default: the thing you open Inventory to find
            // is what is about to run out.
            ->defaultSort('qty')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return Inertia::render('Inventory/Index', [
            'stocks' => $stocks,
            'filters' => $filters,
            'stores' => $user->isAdmin()
                ? Store::orderBy('name')->get(['id', 'name'])
                : Store::whereKey($user->store_id)->get(['id', 'name']),
            'movements' => $this->recentMovements($user),
            'summary' => $this->summary($user),
        ]);
    }

    /**
     * Describes the movement in the terms it was entered in, when whoever
     * entered it did not write a note: "5 × 12 per កេស" beats a bare 60.
     *
     * @param  array<string, mixed>  $data
     */
    private function movementNote(array $data, int $unitsEach): ?string
    {
        if ($unitsEach <= 1) {
            return null;
        }

        $container = trim((string) ($data['unit_label'] ?? '')) ?: 'pack';
        $note = sprintf('%d × %d per %s', $data['quantity'], $unitsEach, $container);
        $loose = (int) ($data['loose'] ?? 0);

        return $loose > 0 ? $note.sprintf(', plus %d loose', $loose) : $note;
    }

    /**
     * Products matching a search, for the "adjust any product" picker.
     *
     * JSON rather than an Inertia reload: the picker has to reach the whole
     * catalogue, not the twenty rows the table happens to be showing, and
     * re-rendering the page on every keystroke to do that would be absurd.
     */
    public function lookup(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q'));

        $rows = $this->scoped($request->user())
            ->with(['product:id,name,sku,barcode,unit', 'store:id,name'])
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true))
            ->when($search !== '', fn (Builder $query) => $query->whereHas(
                'product',
                fn (Builder $q) => $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
            ))
            // Alphabetical by product: a picker should land where the eye
            // expects, unlike the table which leads with the lowest stock.
            ->join('products', 'products.id', '=', 'stocks.product_id')
            ->orderBy('products.name')
            ->orderBy('stocks.store_id')
            ->select('stocks.*')
            ->limit(20)
            ->get()
            ->map(fn (Stock $stock) => [
                'id' => $stock->id,
                'qty' => $stock->qty,
                'low_stock_threshold' => $stock->low_stock_threshold,
                'product' => [
                    'id' => $stock->product->id,
                    'name' => $stock->product->name,
                    'sku' => $stock->product->sku,
                    'barcode' => $stock->product->barcode,
                    'unit' => $stock->product->unit,
                ],
                'store' => ['id' => $stock->store->id, 'name' => $stock->store->name],
            ]);

        return response()->json(['results' => $rows]);
    }

    /**
     * Record a movement. The quantity is a consequence of it, never the input.
     */
    public function store(Request $request): RedirectResponse
    {
        try {
            $user = $request->user();

            // Inventory has no model policy — stock rows are written through
            // movements, not edited — so the action gate sits here.
            abort_unless($user->mayDo(Permission::Inventory, Action::Update), 403);

            $data = $request->validate([
                'stock_id' => ['required', 'integer', Rule::exists('stocks', 'id')],
                'mode' => ['required', Rule::in(['restock', 'remove', 'count', 'return'])],
                // For restock/remove/return this is a delta; for count it is the
                // shelf figure the counter actually saw.
                'quantity' => ['required', 'integer', 'min:0'],

                /*
                 * Goods arrive and are counted the way they are boxed: five cases
                 * of twelve and three loose, not sixty-three. Both optional and
                 * blank by default — sizes vary by product, so nothing is assumed.
                 * `unit_label` is only a label for the note; it never becomes a
                 * pack and never reaches the POS grid.
                 */
                'units_each' => ['nullable', 'integer', 'min:1', 'max:100000'],
                'unit_label' => ['nullable', 'string', 'max:30'],
                'loose' => ['nullable', 'integer', 'min:0', 'max:1000000'],

                'note' => ['nullable', 'string', 'max:255'],
            ]);

            $stock = $this->scoped($user)->whereKey($data['stock_id'])->firstOrFail();

            // Whatever was typed, in single units. A count of "5 cases and 3" is
            // still one absolute figure once it is multiplied out.
            $unitsEach = max(1, (int) ($data['units_each'] ?? 1));
            $quantity = ($data['quantity'] * $unitsEach) + max(0, (int) ($data['loose'] ?? 0));

            [$change, $type] = match ($data['mode']) {
                'restock' => [$quantity, InventoryLogType::Restock],
                'remove' => [-$quantity, InventoryLogType::Adjustment],
                'return' => [$quantity, InventoryLogType::Return],
                // A count is absolute: the delta is whatever reconciles the books
                // to the shelf, which may well be negative.
                'count' => [$quantity - $stock->qty, InventoryLogType::Adjustment],
            };

            if ($change === 0) {
                return back()->with('success', 'Nothing to record — the count already matches.');
            }

            DB::transaction(function () use ($stock, $change, $type, $data, $unitsEach, $user) {
                // A single SQL statement, so two people counting at once cannot
                // read-modify-write over each other.
                $stock->increment('qty', $change);

                InventoryLog::create([
                    'product_id' => $stock->product_id,
                    'store_id' => $stock->store_id,
                    'type' => $type,
                    'qty_change' => $change,
                    'reference_type' => Stock::class,
                    'reference_id' => $stock->id,
                    'note' => ($data['note'] ?? null) ?: $this->movementNote($data, $unitsEach),
                    'created_by' => $user->id,
                ]);
            });

            $stock->refresh();

            return back()->with(
                'success',
                sprintf(
                    '%s: %s%d %s — now %d on hand.',
                    $stock->product->name,
                    $change > 0 ? '+' : '',
                    $change,
                    $stock->product->unit,
                    $stock->qty,
                )
            );
        } catch (QueryException $e) {
            return $this->failed($e, 'The stock movement could not be recorded. The shelf count is unchanged — try again.');
        }
    }

    /** The low-stock threshold is a setting on the row, not a movement. */
    public function updateThreshold(Request $request): RedirectResponse
    {
        try {
            $user = $request->user();

            abort_unless($user->mayDo(Permission::Inventory, Action::Update), 403);

            $data = $request->validate([
                'stock_id' => ['required', 'integer', Rule::exists('stocks', 'id')],
                'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            ]);

            $stock = $this->scoped($user)->whereKey($data['stock_id'])->firstOrFail();
            DB::transaction(fn () => $stock->update(['low_stock_threshold' => $data['low_stock_threshold']]));

            return back()->with('success', 'Low-stock alert updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The alert level could not be saved — try again.');
        }
    }

    private function recentMovements(User $user): Collection
    {
        return InventoryLog::query()
            ->with(['product:id,name,unit', 'store:id,name', 'creator:id,name'])
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id))
            ->latest('id')
            ->limit(15)
            ->get();
    }

    /** @return array{tracked: int, units: int, low: int, out: int, oversold: int} */
    private function summary(User $user): array
    {
        $base = fn () => $this->scoped($user)
            ->whereHas('product', fn (Builder $q) => $q->where('is_active', true));

        return [
            'tracked' => $base()->count(),
            // Physical units on the shelf. Oversold rows are negative and
            // would silently shrink the number, so only what exists counts.
            'units' => (int) $base()->where('qty', '>', 0)->sum('qty'),
            'low' => $base()
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('qty', '<=', 'low_stock_threshold')
                ->where('qty', '>=', 0)
                ->count(),
            'out' => $base()->where('qty', '=', 0)->count(),
            'oversold' => $base()->where('qty', '<', 0)->count(),
        ];
    }

    /** Admins see every store; everyone else is pinned to their own. */
    private function scoped(User $user): Builder
    {
        return Stock::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->where('store_id', $user->store_id));
    }
}
