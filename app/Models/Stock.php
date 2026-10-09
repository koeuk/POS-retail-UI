<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stock extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = ['product_id', 'store_id', 'qty', 'low_stock_threshold'];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'product_id',
        'store_id',
        'qty',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function auditLabel(): ?string
    {
        return $this->product?->name;
    }
}
