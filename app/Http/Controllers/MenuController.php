<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Public, unauthenticated product catalogue.
 *
 * Deliberately read-only: no cart, no stock figures, no staff data. The only
 * thing leaving the server here is what a customer would see on a printed
 * menu board, so it is safe to expose without a session.
 */
class MenuController extends Controller
{
    public function index(Request $request): Response
    {
        $search = trim((string) $request->input('search'));
        $categoryId = $request->input('category');

        $products = Product::query()
            ->active()
            // Base products only. A case and a can are one item on a menu with
            // two prices, not two entries a customer has to reconcile.
            ->base()
            ->with('category:id,name')
            ->with(['packs' => fn ($q) => $q->active()->orderBy('units_per_pack')])
            // The menu is public and store-less, so "sold out" means no store
            // anywhere has it — the total across shelves, not one shop's count.
            ->withSum('stocks as stock_sum', 'qty')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->orderBy('name')
            ->get()
            ->map(function (Product $p) {
                $shelf = (int) ($p->stock_sum ?? 0);

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'description' => $p->description,
                    'image' => $p->image,
                    'unit' => $p->unit,
                    'category_id' => $p->category_id,
                    'category_name' => $p->category?->name,

                    /*
                     * Every way this item can be bought, cheapest first. The base
                     * product is always the first entry — it is the single unit.
                     */
                    'packs' => $p->packs
                        // A pack sells off the parent's shelf: a case is gone
                        // once the loose count can no longer fill one.
                        ->map(fn (Product $pack) => [
                            'id' => $pack->id,
                            'name' => $pack->name,
                            'units' => $pack->units_per_pack,
                            'price' => (float) $pack->sell_price,
                            'sold_out' => $pack->track_stock && intdiv($shelf, max(1, $pack->units_per_pack)) <= 0,
                        ])
                        ->values(),

                    'price' => (float) $p->sell_price,

                    // A boolean only — actual quantities stay off this public page.
                    'sold_out' => $p->track_stock && $shelf <= 0,
                ];
            })
            ->values();

        // Only categories that actually have something to show.
        $usedCategoryIds = $products->pluck('category_id')->unique();

        return Inertia::render('Menu/Index', [
            'products' => $products,
            'categories' => Category::query()
                ->whereIn('id', $usedCategoryIds)
                ->orderBy('name')
                ->get(['id', 'name']),
            'filters' => [
                'search' => $search,
                'category' => $categoryId ? (int) $categoryId : null,
            ],
            'shop' => [
                'name' => Setting::get('receipt_header', config('app.name')),
                'footer' => Setting::get('receipt_footer'),
                'currency' => Currency::current()->toArray(),
            ],
        ]);
    }

    /**
     * One item, still public: the same fields the card shows plus the photo
     * gallery and full description — never stock counts or staff data.
     */
    public function show(Product $product): Response
    {
        // Inactive items and packs have no page of their own: a pack is a
        // buying option on its parent's page, not a separate menu entry.
        abort_unless($product->is_active && $product->parent_product_id === null, 404);

        $product->load('category:id,name')
            ->load(['packs' => fn ($q) => $q->active()->orderBy('units_per_pack')])
            ->loadSum('stocks as stock_sum', 'qty');

        $shelf = (int) ($product->stock_sum ?? 0);

        return Inertia::render('Menu/Show', [
            'product' => [
                'id' => $product->id,
                'name' => $product->name,
                'description' => $product->description,
                'image' => $product->image,
                'gallery' => $product->gallery ?? [],
                'unit' => $product->unit,
                'category_name' => $product->category?->name,
                'price' => (float) $product->sell_price,
                'sold_out' => $product->track_stock && $shelf <= 0,
                'packs' => $product->packs
                    ->map(fn (Product $pack) => [
                        'id' => $pack->id,
                        'name' => $pack->name,
                        'units' => $pack->units_per_pack,
                        'price' => (float) $pack->sell_price,
                        'sold_out' => $pack->track_stock && intdiv($shelf, max(1, $pack->units_per_pack)) <= 0,
                    ])
                    ->values(),
            ],
            'shop' => [
                'name' => Setting::get('receipt_header', config('app.name')),
                'footer' => Setting::get('receipt_footer'),
                'currency' => Currency::current()->toArray(),
            ],
        ]);
    }
}
