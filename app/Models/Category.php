<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = ['name'];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'name',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
