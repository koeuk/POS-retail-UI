<?php

namespace App\Enums;

/**
 * One key per feature area — the unit a user can be granted or denied.
 *
 * A user's role sets the defaults; per-user overrides live in the
 * users.permissions JSON column. Admins bypass the whole table: an admin
 * always holds every permission, so nobody can lock the shop out of its
 * own back office.
 */
enum Permission: string
{
    case Pos = 'pos';
    case Orders = 'orders';
    case Debts = 'debts';
    case Consumption = 'consumption';
    case Reports = 'reports';
    case Products = 'products';
    case Categories = 'categories';
    case Inventory = 'inventory';
    case Customers = 'customers';
    case Users = 'users';
    case Stores = 'stores';
    case Activity = 'activity';
    case Vendors = 'vendors';

    public function label(): string
    {
        return match ($this) {
            self::Pos => 'Point of Sale',
            self::Orders => 'Order history',
            self::Debts => 'In Debt',
            self::Consumption => 'Myself (consumption)',
            self::Reports => 'Reports',
            self::Products => 'Products',
            self::Categories => 'Categories',
            self::Inventory => 'Inventory',
            self::Customers => 'Customers',
            self::Users => 'Staff',
            self::Stores => 'Stores & registers',
            self::Activity => 'Activity log',
            self::Vendors => 'Vendors',
        };
    }

    /** Section header the settings dialog groups the switch under. */
    public function group(): string
    {
        return match ($this) {
            self::Pos, self::Orders, self::Debts, self::Consumption, self::Reports => 'Selling',
            self::Products, self::Categories, self::Inventory => 'Catalogue',
            self::Customers, self::Users, self::Stores, self::Vendors => 'People & stores',
            self::Activity => 'Audit',
        };
    }

    /** What this permission is worth before any per-user override. */
    public function defaultFor(Role $role): bool
    {
        return match ($role) {
            Role::Admin => true,
            // The audit trail records what managers themselves do, so it is
            // admin-only by default — grant it per user on the Staff screen.
            Role::Manager => ! in_array($this, [self::Users, self::Activity, self::Vendors], true),
            Role::Cashier => $this === self::Pos,
            // A supplier's own login runs the shop like a manager and may hire
            // its own cashiers (UserPolicy keeps it to those), but never sees
            // the audit trail or the other suppliers' figures.
            Role::Vendor => ! in_array($this, [self::Activity, self::Vendors], true),
        };
    }

    /**
     * One action's baseline for a role, before any per-user override.
     *
     * Follows the area default, with one role-level exception: a vendor
     * account may add and edit but not delete. Grant delete per user on the
     * Staff screen when a particular vendor needs it.
     */
    public function defaultActionFor(Role $role, Action $action): bool
    {
        if ($role === Role::Vendor && $action === Action::Delete) {
            return false;
        }

        return $this->defaultFor($role);
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
