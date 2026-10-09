<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\SaleType;
use App\Models\Concerns\HasUuid;
use App\Services\SalesReporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'client_uuid',
        'order_no',
        'store_id',
        'register_id',
        'cashier_id',
        'customer_id',
        'sale_type',
        'currency',
        'subtotal',
        'discount_amount',
        'total',
        'paid_amount',
        'change_amount',
        'status',
        'synced_at',
        'created_offline_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'status' => OrderStatus::class,
            'sale_type' => SaleType::class,
            'synced_at' => 'datetime',
            'created_offline_at' => 'datetime',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** What is still owed on this sale. Zero for anything but an unpaid debt. */
    public function outstanding(): string
    {
        return number_format(max(0, (float) $this->total - (float) $this->paid_amount), 2, '.', '');
    }

    /* ------------------------------------------------------------------ */
    /* Business-day filtering — see SalesReporter::businessDay() for why. */
    /* ------------------------------------------------------------------ */

    /** Sales on or after the shop's day `$date` (Y-m-d). */
    public function scopeBusinessDayFrom(Builder $query, string $date): Builder
    {
        return $query->where(SalesReporter::day(), '>=', $date);
    }

    /** Sales on or before the shop's day `$date` (Y-m-d). */
    public function scopeBusinessDayTo(Builder $query, string $date): Builder
    {
        return $query->where(SalesReporter::day(), '<=', $date);
    }

    /** Newest sale first, by when it happened — not when it reached the server. */
    public function scopeLatestByBusinessMoment(Builder $query): Builder
    {
        return $query->orderByDesc(SalesReporter::moment());
    }
}
