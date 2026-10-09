<?php

namespace App\Policies;

use App\Enums\Action;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Customer $customer): bool
    {
        return true;
    }

    /** Cashiers add walk-in customers at the till, so create is open. */
    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Customer $customer): bool
    {
        return $user->mayDo(Permission::Customers, Action::Update);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->mayDo(Permission::Customers, Action::Delete);
    }
}
