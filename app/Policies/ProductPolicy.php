<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /** Admins bypass every check below. */
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** Cashiers need to read the catalogue for the POS grid. */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->mayDo(Permission::Products, Action::Create);
    }

    public function update(User $user, Product $product): bool
    {
        return $user->mayDo(Permission::Products, Action::Update);
    }

    public function delete(User $user, Product $product): bool
    {
        return $user->mayDo(Permission::Products, Action::Delete);
    }
}
