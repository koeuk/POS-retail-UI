<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\User;
use App\Models\Vendor;

class VendorPolicy
{
    /** Admins bypass every check below. */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::Vendors);
    }

    public function view(User $user, Vendor $vendor): bool
    {
        return $user->mayDo(Permission::Vendors, Action::View);
    }

    public function create(User $user): bool
    {
        return $user->mayDo(Permission::Vendors, Action::Create);
    }

    public function update(User $user, Vendor $vendor): bool
    {
        return $user->mayDo(Permission::Vendors, Action::Update);
    }

    public function delete(User $user, Vendor $vendor): bool
    {
        return $user->mayDo(Permission::Vendors, Action::Delete);
    }
}
