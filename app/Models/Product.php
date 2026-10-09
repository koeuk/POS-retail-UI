<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = [
        'category_id',
        'vendor_id',
        'parent_product_id',
        'name',
        'sku',
        'barcode',
        'description',
        'cost_price',
        'sell_price',
        'image',
        'gallery',
        'unit',
        'units_per_pack',
        'case_size',
        'track_stock',
        'is_active',
    ];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'category_id',
        'vendor_id',
        'name',
        'sku',
        'barcode',
        'cost_price',
        'sell_price',
        'unit',
        'units_per_pack',
        'case_size',
        'track_stock',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'gallery' => 'array',
            'cost_price' => 'decimal:2',
            'sell_price' => 'decimal:2',
            'units_per_pack' => 'integer',
            'case_size' => 'integer',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /** The product this one is a pack of, if any. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_product_id');
    }

    /** The pack sizes sold against this product — case, six-pack, single. */
    public function packs(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_product_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function inventoryLogs(): HasMany
    {
        return $this->hasMany(InventoryLog::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Only products that hold stock in their own right. */
    public function scopeBase(Builder $query): Builder
    {
        return $query->whereNull('parent_product_id');
    }

    public function isPack(): bool
    {
        return $this->parent_product_id !== null;
    }

    /**
     * The row whose stock this product moves.
     *
     * A case of beer has no shelf of its own — selling one takes 24 cans off
     * the base product. Everything to do with stock goes through here rather
     * than reaching for `$product->id` directly.
     */
    public function stockProductId(): int
    {
        return $this->parent_product_id ?? $this->id;
    }

    /** Base units consumed by selling `$qty` of this product. */
    public function baseUnits(int $qty = 1): int
    {
        return $qty * max(1, $this->units_per_pack);
    }
}
