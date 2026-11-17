<?php

namespace App\Support\Access;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Database\Eloquent\Builder;

/**
 * The two data rules screen permissions cannot express (spec §6), both switched on the Lists &
 * options screen:
 *  - who sees supplier identities and supplier prices: anyone who may view the Suppliers
 *    screen, plus sales when `sales_see_supplier_prices` is on;
 *  - whether a sales user sees only the clients they manage (`sales_own_clients_only`).
 */
final class Visibility
{
    public static function canSeeSupplierPrices(User $user): bool
    {
        return $user->can('suppliers.view')
            || (BusinessSetting::current()->sales_see_supplier_prices && $user->hasRole(Roles::SALES));
    }

    /** True when $user must see only the clients whose account manager they are. */
    public static function ownClientsOnly(User $user): bool
    {
        return BusinessSetting::current()->sales_own_clients_only
            && $user->hasRole(Roles::SALES)
            && ! $user->hasAnyRole([Roles::MANAGER, Roles::ACCOUNTANT, Roles::MERCHANDISER, Roles::LOGISTICS]);
    }

    /** Narrow a query on parties (clients) to what $user may see. */
    public static function scopeClients(Builder $query, User $user, string $column = 'account_manager_id'): Builder
    {
        return self::ownClientsOnly($user) ? $query->where($column, $user->id) : $query;
    }
}
