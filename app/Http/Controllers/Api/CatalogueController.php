<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\PerPage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * Read side of the catalogue. Same filter grammar as the web screens —
 * `filter[search]`, `filter[category_id]`, `filter[status]` — so anything
 * learned on one surface transfers to the other.
 */
class CatalogueController extends Controller
{
    /**
     * List products
     *
     * Base products only; a pack rides along inside its parent. Requires the
     * `products` permission.
     *
     * @group Catalogue
     *
     * @queryParam filter[search] string Matches name, SKU or barcode. Example: cola
     * @queryParam filter[category_id] integer Example: 2
     * @queryParam filter[status] string `active` or `inactive`. Example: active
     * @queryParam per_page integer One of 10, 20, 50, 100, 150, 200. Example: 20
     *
     * @response {"current_page": 1, "data": [{"id": 19, "name": "Wurkz", "sku": "SKU-0010", "sell_price": "2000.00", "unit": "can", "is_active": true, "stock_qty": "17", "category": {"id": 2, "name": "Drinks"}, "packs": []}], "per_page": 20, "total": 1}
     */
    public function products(Request $request): JsonResponse
    {
        $products = QueryBuilder::for(Product::class)
            ->base()
            ->with('category:id,name')
            ->with(['packs:id,parent_product_id,name,units_per_pack,sell_price,is_active'])
            ->withSum('stocks as stock_qty', 'qty')
            ->allowedFilters(...[
                AllowedFilter::callback('search', function (Builder $query, string $search) {
                    $query->where(fn (Builder $q) => $q
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%")
                        ->orWhere('barcode', 'like', "%{$search}%"));
                }),
                AllowedFilter::exact('category_id'),
                AllowedFilter::callback('status', fn (Builder $q, string $status) => $q->where('is_active', $status === 'active')),
            ])
            ->latest('id')
            ->paginate(PerPage::resolve($request))
            ->withQueryString();

        return response()->json($products);
    }

    /**
     * One product
     *
     * With its category, pack sizes, and per-store stock rows.
     *
     * @group Catalogue
     */
    public function product(Product $product): JsonResponse
    {
        return response()->json($product->load([
            'category:id,name',
            'packs:id,parent_product_id,name,units_per_pack,sell_price,is_active',
            'stocks:id,product_id,store_id,qty,low_stock_threshold',
        ]));
    }

    /**
     * List categories
     *
     * Alphabetical, with a product count each. Requires the `categories` permission.
     *
     * @group Catalogue
     */
    public function categories(): JsonResponse
    {
        return response()->json(
            Category::query()->withCount('products')->orderBy('name')->get()
        );
    }
}
