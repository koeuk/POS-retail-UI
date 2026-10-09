<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * The shop's audit trail row.
 *
 * Extends Spatie's model to carry the three columns an audit needs but the
 * package does not ship: which store the action happened in, and the IP and
 * user agent it came from. `ActivityContext` fills them on every write.
 *
 * Log names group entries by what kind of thing happened — the Activity
 * screen filters on them, so they are constants rather than loose strings.
 */
class Activity extends SpatieActivity
{
    /** Model create/update/delete, recorded by the LogsActivity trait. */
    public const LOG_MODEL = 'model';

    /** Sign in, sign out, and failed sign-in attempts. */
    public const LOG_AUTH = 'auth';

    /** Sales, voids, payments and debt settlements — the cash trail. */
    public const LOG_MONEY = 'money';

    /** Role changes and per-user permission grants or revokes. */
    public const LOG_ACCESS = 'access';

    protected $fillable = [
        'log_name',
        'description',
        'subject_id',
        'subject_type',
        'event',
        'causer_id',
        'causer_type',
        'store_id',
        'attribute_changes',
        'properties',
        'ip_address',
        'user_agent',
    ];

    /** @return array<string, string> */
    public static function logNames(): array
    {
        return [
            self::LOG_MODEL => 'Record changes',
            self::LOG_AUTH => 'Sign in & out',
            self::LOG_MONEY => 'Sales & money',
            self::LOG_ACCESS => 'Roles & permissions',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
