<?php

namespace App\Support;

use App\Models\Activity;
use Illuminate\Database\Eloquent\Model;

/**
 * The one way this app writes non-model audit entries.
 *
 * Model create/update/delete is handled automatically by Spatie's
 * LogsActivity trait. Everything else — money moving, someone signing in, a
 * permission changing — is an *event*, not a row edit, so it is recorded
 * explicitly through these helpers. Keeping them here means the log names
 * and property shapes stay consistent across controllers.
 */
class AuditLog
{
    /** Sales, voids, payments, debt settlements. */
    public static function money(string $description, ?Model $subject = null, array $properties = [], ?string $event = null): void
    {
        self::write(Activity::LOG_MONEY, $description, $subject, $properties, $event);
    }

    /** Sign in, sign out, failed attempts. */
    public static function auth(string $description, ?Model $subject = null, array $properties = [], ?string $event = null): void
    {
        self::write(Activity::LOG_AUTH, $description, $subject, $properties, $event);
    }

    /** Role changes and permission grants or revokes. */
    public static function access(string $description, ?Model $subject = null, array $properties = [], ?string $event = null): void
    {
        self::write(Activity::LOG_ACCESS, $description, $subject, $properties, $event);
    }

    private static function write(string $log, string $description, ?Model $subject, array $properties, ?string $event): void
    {
        $entry = activity($log)->withProperties($properties);

        if ($subject) {
            $entry->performedOn($subject);
        }

        if ($event) {
            $entry->event($event);
        }

        $entry->log($description);
    }
}
