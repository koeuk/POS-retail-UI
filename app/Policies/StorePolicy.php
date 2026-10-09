<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Store;
use App\Models\User;

class StorePolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Stores);
    }

    /** Managers only ever see their own store. */
    public function view(User $user, Store $store): bool
    {
        return $user->hasPermission(Permission::Stores) && $user->store_id === $store->id;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Store $store): bool
    {
        return false;
    }

    /**
     * Admins only, and `before()` already grants them everything — this is
     * the rule for everyone else. Whether a *particular* store may go is a
     * data question (orders, staff, being the last one), and that lives in
     * StoreController::destroy where a refusal can explain itself.
     */
    public function delete(User $user, Store $store): bool
    {
        return false;
    }
}
