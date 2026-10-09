<?php

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Models\Concerns\RecordsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A supplier the shop buys stock from. */
class Vendor extends Model
{
    use HasFactory, HasUuid, RecordsActivity;

    protected $fillable = ['name', 'contact_name', 'phone', 'email', 'address', 'notes', 'is_active'];

    /** Columns the audit trail records changes to — see RecordsActivity. */
    protected array $auditable = [
        'name',
        'contact_name',
        'phone',
        'email',
        'address',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** Staff accounts signed in as this vendor. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
