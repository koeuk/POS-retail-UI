<?php

namespace App\Observers;

use App\Models\Activity;
use Illuminate\Support\Facades\Request;

/**
 * Stamps request context onto every audit row.
 *
 * This hangs off the model's creating event rather than each call site so it
 * cannot be forgotten: the LogsActivity trait, the activity() helper and any
 * hand-written entry all pass through here and come out with the same shape.
 *
 * Console runs (seeders, scheduled jobs) have no request — those rows keep
 * null context rather than inventing an IP.
 */
class ActivityObserver
{
    public function creating(Activity $activity): void
    {
        if ($activity->store_id === null) {
            // Where the action happened. Cashiers are bound to a store;
            // admins and managers are store-agnostic and record null.
            $activity->store_id = auth()->user()?->store_id;
        }

        if (app()->runningInConsole()) {
            return;
        }

        $activity->ip_address ??= Request::ip();
        $activity->user_agent ??= substr((string) Request::userAgent(), 0, 255);
    }
}
