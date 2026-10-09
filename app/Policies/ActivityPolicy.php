<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Activity;
use App\Models\User;

/**
 * The audit trail is read-only by design: there is no update or delete
 * ability here, because an audit log a user can edit is not an audit log.
 * Old rows are pruned on a schedule instead (activitylog:clean).
 */
class ActivityPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Activity);
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasPermission(Permission::Activity);
    }
}
