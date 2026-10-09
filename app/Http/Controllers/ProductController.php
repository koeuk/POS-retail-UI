<?php

namespace App\Http\Controllers;

use App\Enums\InventoryLogType;
use App\Enums\OrderStatus;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\InventoryLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Store;
use App\Models\User;
use App\Models\Vendor;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Product::class);

        $filters = [
            'search' => (string) $request->input('filter.search', ''),
            'category_id' => (string) $request->input('filter.category_id', ''),
            'status' => (string) $request->input('filter.status', ''),
        ];

        $products = QueryBuilder::for(Product::class)
            /*
             * Base products only. A pack is a way of buying this product, not a
             * product of its own — it owns no stock, so listing it puts rows
             * reading "0 pcs" beside the real item and doubles the catalogue.
             * Its price appears on the parent's row as a range instead.
             */
            ->base()
            ->with('category:id,name')
            ->withSum('stocks as stock_qty', 'qty')
            ->withCount('packs')
            // Cheapest and dearest way to buy it, for the range in the list.
            ->withMin('packs as pack_min_price', 'sell_price')
            ->withMax('packs as pack_max_price', 'sell_price')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(function (Builder $q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('sku', 'like', "%{$search}%")
                            ->orWhere('barcode', 'like', "%{$search}%")
                            // Scanning a case's barcode should find the product it
                            // belongs to, not nothing.
                            ->orWhereHas('packs', fn ($p) => $p
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%")
                                ->orWhere('barcode', 'like', "%{$search}%"));
                    });
                }),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('status', fn (Builder $q, string $status) => $q->where('is_active', $status === 'active')),
            ])
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return Inertia::render('Products/Index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Product::class);

        return Inertia::render('Products/Create', [
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'is_active']),
        ]);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        try {
            $this->authorize('create', Product::class);

            $data = $request->validated();
            $openingQty = (int) ($data['opening_qty'] ?? 0);
            $threshold = $data['low_stock_threshold'] ?? null;
            $packs = $data['packs'] ?? [];
            $imageUrl = $data['image_url'] ?? null;
            $galleryUrls = $data['gallery_urls'] ?? [];
            unset(
                $data['opening_qty'], $data['low_stock_threshold'], $data['packs'],
                $data['image_url'], $data['gallery'], $data['gallery_urls'], $data['gallery_existing'],
            );

            if ($request->hasFile('image')) {
                $data['image'] = $request->file('image')->store('products', 'public');
            } elseif ($imageUrl) {
                // A pasted link is stored as-is; the frontend renders http(s)
                // sources directly instead of prefixing /storage.
                $data['image'] = $imageUrl;
            }

            $data['gallery'] = $this->buildGallery($request->file('gallery') ?? [], $galleryUrls);

            $product = DB::transaction(function () use ($data, $openingQty, $threshold, $packs, $request) {
                $product = Product::create($data);

                $this->syncPacks($product, $packs);

                // A pack draws stock from its parent, so it must not own rows of
                // its own — two shelves for one physical crate is the exact
                // disagreement this feature exists to avoid.
                if ($product->isPack()) {
                    return $product;
                }

                // Seed a stock row per store so the POS product feed always has
                // one to read, even when opening quantity is zero.
                foreach (Store::pluck('id') as $storeId) {
                    Stock::create([
                        'product_id' => $product->id,
                        'store_id' => $storeId,
                        'qty' => $openingQty,
                        'low_stock_threshold' => $threshold,
                    ]);

                    if ($openingQty > 0) {
                        InventoryLog::create([
                            'product_id' => $product->id,
                            'store_id' => $storeId,
                            'type' => InventoryLogType::Restock,
                            'qty_change' => $openingQty,
                            'reference_type' => Product::class,
                            'reference_id' => $product->id,
                            'note' => 'Opening stock',
                            'created_by' => $request->user()->id,
                        ]);
                    }
                }

                return $product;
            });

            return to_route('products.index')
                ->with('success', "“{$product->name}” was created.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The product could not be saved. Nothing was changed — try again.');
        }
    }

    /**
     * Read-only view. Everything about one product in one place: what it is,
     * where the stock sits, how it has moved and how it has sold. Editing
     * stays on its own screen so a glance can never become an accident.
     */
    public function show(Product $product): Response
    {
        $this->authorize('view', $product);

        // How it has actually sold, from the snapshotted line items.
        $sold = OrderItem::query()
            ->where('order_items.product_id', $product->id)
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Completed->value);

        return Inertia::render('Products/Show', [
            'product' => $product->load('category:id,name'),
            // Every way this can be bought. The list page shows only the range,
            // so this is where the individual prices live.
            'packs' => $product->packs()
                ->orderBy('units_per_pack')
                ->get(['id', 'name', 'units_per_pack', 'sell_price', 'is_active']),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
            'movements' => $product->inventoryLogs()
                ->with(['store:id,name', 'creator:id,name'])
                ->latest('id')
                ->limit(10)
                ->get(),
            'sales' => [
                'qty' => (int) (clone $sold)->sum('order_items.qty'),
                'revenue' => number_format((float) $sold->sum('order_items.subtotal'), 2, '.', ''),
            ],
        ]);
    }

    public function edit(Product $product): Response
    {
        $this->authorize('update', $product);

        return Inertia::render('Products/Edit', [
            'product' => $product->load('category:id,name', 'parent:id,name'),
            'categories' => Category::orderBy('name')->get(['id', 'name']),
            'vendors' => Vendor::orderBy('name')->get(['id', 'name', 'is_active']),
            'packs' => $product->packs()->orderBy('units_per_pack')->get(['id', 'name', 'units_per_pack', 'sell_price', 'is_active']),
            // Only worth offering a choice when there is one to make.
            'stores' => Store::orderBy('name')->get(['id', 'name']),
            'stocks' => $product->stocks()->with('store:id,name')->get(),
        ]);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        try {
            $this->authorize('update', $product);

            $data = $request->validated();
            $packs = $data['packs'] ?? [];
            $receipt = [
                'qty' => (int) ($data['add_stock'] ?? 0),
                'pack_id' => $data['add_stock_pack_id'] ?? null,
                'units_each' => (int) ($data['add_stock_units_each'] ?? 0),
                'unit_label' => $data['add_stock_unit_label'] ?? null,
                'loose' => (int) ($data['add_stock_loose'] ?? 0),
                'store_id' => $data['add_stock_store_id'] ?? null,
                'note' => $data['add_stock_note'] ?? null,
            ];
            $imageUrl = $data['image_url'] ?? null;
            $galleryUrls = $data['gallery_urls'] ?? [];
            $galleryKept = $data['gallery_existing'] ?? [];
            unset(
                $data['opening_qty'],
                $data['low_stock_threshold'],
                $data['packs'],
                $data['add_stock'],
                $data['add_stock_pack_id'],
                $data['add_stock_units_each'],
                $data['add_stock_unit_label'],
                $data['add_stock_loose'],
                $data['add_stock_store_id'],
                $data['add_stock_note'],
                $data['image_url'],
                $data['gallery'],
                $data['gallery_urls'],
                $data['gallery_existing'],
            );

            if ($request->hasFile('image')) {
                $this->deleteLocalImage($product->image);
                $data['image'] = $request->file('image')->store('products', 'public');
            } elseif ($imageUrl) {
                $this->deleteLocalImage($product->image);
                $data['image'] = $imageUrl;
            } else {
                unset($data['image']);
            }

            /*
             * The kept list is authoritative: whatever was saved before and is
             * no longer in it is being removed, so its local file goes too.
             * Only sources the product really had count — the client cannot
             * delete arbitrary files by "keeping" paths it invents.
             */
            $old = $product->gallery ?? [];
            $kept = array_values(array_intersect($old, $galleryKept));
            foreach (array_diff($old, $kept) as $droppedSrc) {
                $this->deleteLocalImage($droppedSrc);
            }
            $data['gallery'] = [...$kept, ...$this->buildGallery($request->file('gallery') ?? [], $galleryUrls)];

            DB::transaction(function () use ($product, $data, $packs, $receipt, $request) {
                $product->update($data);

                // A pack has no packs of its own, so the list is only meaningful on
                // a base product and is ignored entirely on a pack.
                if (! $product->isPack() && $request->has('packs')) {
                    $this->syncPacks($product, $packs);
                }

                $this->receiveStock($product, $receipt, $request->user());
            });

            return to_route('products.index')
                ->with('success', "“{$product->name}” was updated.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The product could not be saved. Nothing was changed — try again.');
        }
    }

    /**
     * Record goods arriving, from the product screen.
     *
     * This is a shortcut to the Inventory page, not a way around it: the
     * quantity still moves through a Restock movement with a note and an
     * author, so the ledger can still answer why the figure changed. Writing
     * stocks.qty directly here would leave a number nobody could explain.
     *
     * A pack has no shelf of its own, so its receipts land on the parent in
     * base units — receiving two cases of 24 adds 48 cans.
     */
    /**
     * Record goods arriving, counted the way the delivery actually came.
     *
     * A shop receives three cases and a hundred loose packets, not 172
     * packets. The pack says what `qty` is counted in and the loose figure
     * carries the remainder, so nobody has to do the multiplication at 7am.
     *
     * This is a shortcut to the Inventory page, not a way around it: the
     * quantity still moves through a Restock movement with a note and an
     * author, so the ledger can still answer why the figure changed.
     *
     * A pack has no shelf of its own, so receipts land on the parent in base
     * units — three cases of 24 adds 72.
     *
     * @param  array{qty: int, pack_id: int|null, units_each: int, unit_label: string|null, loose: int, store_id: int|null, note: string|null}  $receipt
     */
    private function receiveStock(Product $product, array $receipt, User $user): void
    {
        $target = $product->isPack() ? $product->parent : $product;

        if (! $target) {
            return;
        }

        $unitsEach = $this->unitsPerReceipt($target, $product, $receipt);
        $change = ($receipt['qty'] * $unitsEach) + max(0, $receipt['loose']);

        if ($change <= 0) {
            return;
        }

        $storeId = $receipt['store_id'] ?? Store::query()->orderBy('id')->value('id');

        if (! $storeId) {
            return;
        }

        $stock = Stock::firstOrCreate(
            ['product_id' => $target->id, 'store_id' => $storeId],
            ['qty' => 0],
        );

        $stock->increment('qty', $change);

        InventoryLog::create([
            'product_id' => $target->id,
            'store_id' => $storeId,
            'type' => InventoryLogType::Restock,
            'qty_change' => $change,
            'reference_type' => Product::class,
            'reference_id' => $product->id,
            'note' => $receipt['note'] ?: $this->receiptNote($receipt, $unitsEach),
            'created_by' => $user->id,
        ]);
    }

    /**
     * A note describing the delivery in the terms it arrived in, when the
     * person receiving it did not write one: "10 × 24 cans" beats "240".
     *
     * @param  array{qty: int, unit_label: string|null, loose: int}  $receipt
     */
    private function receiptNote(array $receipt, int $unitsEach): string
    {
        if ($unitsEach <= 1) {
            return 'Received from the product screen';
        }

        $container = trim((string) $receipt['unit_label']) ?: 'pack';
        $note = sprintf('Received %d × %d per %s', $receipt['qty'], $unitsEach, $container);

        return $receipt['loose'] > 0
            ? $note.sprintf(', plus %d loose', $receipt['loose'])
            : $note;
    }

    /**
     * How many base units one of the received things holds.
     *
     * Three ways to say it, in order of authority:
     *
     *  1. a sellable pack of this product — its own size wins;
     *  2. a size typed in for this delivery alone, for a container the shop
     *     buys in but never sells as such;
     *  3. otherwise a single unit.
     *
     * A pack id only counts when it really is a pack of this product —
     * otherwise a hand-edited form could multiply a delivery by someone
     * else's case size.
     *
     * @param  array{pack_id: int|null, units_each: int}  $receipt
     */
    private function unitsPerReceipt(Product $target, Product $edited, array $receipt): int
    {
        if ($receipt['pack_id']) {
            $pack = $target->packs()->whereKey($receipt['pack_id'])->first();

            if ($pack) {
                return max(1, $pack->units_per_pack);
            }
        }

        if ($receipt['units_each'] > 1) {
            return $receipt['units_each'];
        }

        // Editing the pack row itself: its own size is the unit.
        return $edited->isPack() ? max(1, $edited->units_per_pack) : 1;
    }

    /**
     * Bring a base product's pack sizes in line with what was submitted.
     *
     * Rows carrying an id are updated, new rows are created, and anything the
     * form dropped is removed — except a pack that has already been sold,
     * which is deactivated instead so its order history keeps pointing at a
     * real row.
     *
     * @param  array<int, array<string, mixed>>  $packs
     */
    private function syncPacks(Product $product, array $packs): void
    {
        $keptIds = [];

        foreach ($packs as $pack) {
            $attributes = [
                'category_id' => $product->category_id,
                'vendor_id' => $product->vendor_id,
                'parent_product_id' => $product->id,
                'name' => $pack['name'],
                'units_per_pack' => (int) $pack['units_per_pack'],
                'sell_price' => $pack['sell_price'],
                'barcode' => ($pack['barcode'] ?? null) ?: null,
                'unit' => $product->unit,
                'track_stock' => $product->track_stock,
                'is_active' => true,
            ];

            $existing = isset($pack['id'])
                ? $product->packs()->whereKey($pack['id'])->first()
                : null;

            if ($existing) {
                $existing->update($attributes);
                $keptIds[] = $existing->id;

                continue;
            }

            $keptIds[] = Product::create($attributes + [
                'sku' => $this->packSku($product, (int) $pack['units_per_pack']),
            ])->id;
        }

        foreach ($product->packs()->whereKeyNot($keptIds)->get() as $removed) {
            if ($removed->orderItems()->exists()) {
                $removed->update(['is_active' => false]);

                continue;
            }

            $removed->delete();
        }
    }

    /**
     * A pack's SKU is derived, not typed: the shopkeeper is entering a name
     * and a price, and inventing a unique code per size is exactly the chore
     * this form exists to remove.
     */
    private function packSku(Product $product, int $units): string
    {
        $base = mb_substr($product->sku, 0, 50).'-'.$units;
        $sku = $base;
        $suffix = 1;

        while (Product::where('sku', $sku)->exists()) {
            $sku = $base.'-'.$suffix++;
        }

        return $sku;
    }

    public function destroy(Product $product): RedirectResponse
    {
        try {
            $this->authorize('delete', $product);

            // Products that have been sold are restricted by a foreign key on
            // order_items. Deactivate rather than delete — the sales history
            // must keep pointing at a real row.
            // The foreign key would refuse this anyway; saying so plainly beats a
            // 500 from the database.
            if ($product->packs()->exists()) {
                return back()->withErrors([
                    'product' => 'This product has pack sizes. Delete those first.',
                ]);
            }

            if ($product->orderItems()->exists()) {
                $product->update(['is_active' => false]);

                return back()->with('success', "“{$product->name}” has sales history, so it was deactivated instead of deleted.");
            }

            $name = $product->name;

            DB::transaction(function () use ($product) {
                $product->stocks()->delete();
                $product->inventoryLogs()->delete();
                $product->delete();
            });

            $this->deleteLocalImage($product->image);
            foreach ($product->gallery ?? [] as $src) {
                $this->deleteLocalImage($src);
            }

            return back()->with('success', "“{$name}” was deleted.");
        } catch (QueryException $e) {
            return $this->failed($e, 'The product could not be deleted. Nothing was changed — try again.');
        }
    }

    /**
     * Gallery sources for what this request supplied: uploads become
     * public-disk paths, pasted links are kept verbatim.
     */
    private function buildGallery(array $files, array $urls): array
    {
        $sources = [];

        foreach ($files as $file) {
            $sources[] = $file->store('products', 'public');
        }

        return [...$sources, ...$urls];
    }

    /** Remove a stored image file — but a pasted link owns no file here. */
    private function deleteLocalImage(?string $src): void
    {
        if ($src && ! str_starts_with($src, 'http://') && ! str_starts_with($src, 'https://')) {
            Storage::disk('public')->delete($src);
        }
    }
}
