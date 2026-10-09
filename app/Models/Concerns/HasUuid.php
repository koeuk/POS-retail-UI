<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * A public identity separate from the auto-increment key.
 *
 * The numeric id stays the primary key — every foreign key, join and index
 * keeps working — but it never appears in a URL again. The uuid is what the
 * router binds on (see getRouteKeyName), so /products/52/edit becomes
 * /products/9d3f…/edit and row counts stop being guessable from a link.
 */
trait HasUuid
{
    use HasUuids;

    /** Auto-fill `uuid` on create; leave `id` incrementing as it is. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Random v4, not the framework's default ordered v7 — an ordered uuid
     * sorts by creation time, which quietly reintroduces the "how many rows
     * before mine" leak this column exists to close.
     */
    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }
}
