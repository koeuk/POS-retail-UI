<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Register;
use App\Models\Stock;
use App\Models\Store;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Store::class);

        return Inertia::render('Stores/Index', [
            'stores' => Store::query()
                ->with('registers:id,store_id,name,is_active')
                ->withCount(['users', 'orders'])
                ->orderBy('name')
                ->get(),
            'canManage' => $request->user()->isAdmin(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        try {
            $this->authorize('create', Store::class);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'],
            ]);

            $store = DB::transaction(function () use ($data) {
                $store = Store::create($data);

                /*
                 * Backfill a stock row for every existing product.
                 *
                 * Creating a product already seeds a row per store, but a store
                 * created afterwards would have none — its POS would show zero
                 * for the whole catalogue and its shelves would never appear in
                 * the low-stock report. Quantities start at 0; goods are received
                 * through an inventory movement, not by creating a store.
                 */
                $rows = Product::query()
                    // Packs draw stock from their parent, so they get no row here
                    // either — see the products table's parent_product_id.
                    ->base()
                    ->pluck('id')
                    ->map(fn (int $productId) => [
                        // Bulk insert skips Eloquent, so the uuid the model
                        // would auto-fill has to be supplied by hand here.
                        'uuid' => (string) Str::uuid(),
                        'product_id' => $productId,
                        'store_id' => $store->id,
                        'qty' => 0,
                        'low_stock_threshold' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($rows !== []) {
                    Stock::insert($rows);
                }

                return $store;
            });

            return back()->with(
                'success',
                "“{$store->name}” created with {$store->stocks()->count()} product rows at zero stock."
            );
        } catch (QueryException $e) {
            return $this->failed($e, 'The store could not be saved. Nothing was changed — try again.');
        }
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        try {
            $this->authorize('update', $store);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'address' => ['nullable', 'string', 'max:255'],
                'phone' => ['nullable', 'string', 'max:32'],
            ]);

            DB::transaction(fn () => $store->update($data));

            return back()->with('success', 'Store updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The store could not be saved. Nothing was changed — try again.');
        }
    }

    /**
     * Deleting a store is guarded three ways, and each refusal explains
     * itself rather than letting a foreign key throw a 500 at the operator.
     *
     * Orders are the hard one: `orders.store_id` is restrictOnDelete, so the
     * database would refuse anyway — a sale must always point at a real shop.
     * Stock rows, registers and the inventory ledger cascade, which is
     * correct: they describe a place that no longer exists.
     */
    public function destroy(Request $request, Store $store): RedirectResponse
    {
        try {
            $this->authorize('delete', $store);

            if (Store::count() <= 1) {
                return back()->withErrors([
                    'store' => 'This is the only store. The app needs at least one to sell anything.',
                ]);
            }

            if ($store->orders()->exists()) {
                return back()->withErrors([
                    'store' => "“{$store->name}” has sales history and cannot be deleted. Its orders must keep pointing at a real store.",
                ]);
            }

            // A cashier without a store cannot open the POS at all, so make the
            // operator reassign people deliberately rather than silently orphan
            // them via the nullOnDelete foreign key.
            $staff = $store->users()->count();

            if ($staff > 0) {
                return back()->withErrors([
                    'store' => "Move the {$staff} staff member(s) assigned to “{$store->name}” to another store first.",
                ]);
            }

            $name = $store->name;

            // Registers, stock rows and the ledger cascade with the store —
            // one transaction so a failure part-way leaves the shop whole.
            DB::transaction(fn () => $store->delete());

            return back()->with('success', "“{$name}” was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The store could not be deleted. Nothing was changed — try again.');
        }
    }

    public function storeRegister(Request $request, Store $store): RedirectResponse
    {
        try {
            $this->authorize('update', $store);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'is_active' => ['boolean'],
            ]);

            DB::transaction(fn () => $store->registers()->create($data));

            return back()->with('success', 'Register added.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The register could not be added. Nothing was changed — try again.');
        }
    }

    public function updateRegister(Request $request, Store $store, Register $register): RedirectResponse
    {
        try {
            $this->authorize('update', $store);

            abort_unless($register->store_id === $store->id, 404);

            $data = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'is_active' => ['boolean'],
                'store_id' => ['sometimes', Rule::in([$store->id])],
            ]);

            DB::transaction(fn () => $register->update($data));

            return back()->with('success', 'Register updated.');
        } catch (QueryException $e) {
            return $this->failed($e, 'The register could not be saved. Nothing was changed — try again.');
        }
    }
}
