<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * The one authority on who may assign roles and change the role matrix. No bypass role
 * exists, so these rules hold for everybody (spec §10, design A4):
 *
 *  - Nobody changes their own roles or account through user management (the profile page
 *    is theirs; the Users screen is for other people).
 *  - Only a manager grants `manager`; only a system admin grants `system_admin`.
 *  - Nobody assigns a role carrying a permission they do not hold themselves, and nobody
 *    edits an account holding a role they could not assign (rank).
 *  - The two system roles' matrices are locked; nobody edits the matrix of a role they
 *    hold, and nobody grants or removes a permission they do not hold.
 *  - The last active manager, and the last active user who can edit users, stay.
 */
class RoleManagementGuard
{
    /** Roles whose permission matrix nobody edits: they are defined by the seeder. */
    public const SYSTEM_ROLES = [Roles::MANAGER, Roles::SYSTEM_ADMIN];

    public function canAssignRole(User $actor, string $roleName, ?User $target = null): bool
    {
        if ($target && $target->id === $actor->id) {
            return false;
        }

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return false;
        }

        if ($roleName === Roles::MANAGER && ! $actor->hasRole(Roles::MANAGER)) {
            return false;
        }
        if ($roleName === Roles::SYSTEM_ADMIN && ! $actor->hasRole(Roles::SYSTEM_ADMIN)) {
            return false;
        }

        foreach ($role->permissions as $permission) {
            if (! $actor->can($permission->name)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> roles $actor may assign, in the registry's order */
    public function assignableRoleNames(User $actor, ?User $target = null): array
    {
        return array_values(array_filter(Roles::ALL, fn (string $role) => $this->canAssignRole($actor, $role, $target)));
    }

    /** May $actor edit $target's account? Never their own; never above their rank. */
    public function canManageUser(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return false;
        }

        foreach ($target->getRoleNames() as $roleName) {
            if (! $this->canAssignRole($actor, $roleName, $target)) {
                return false;
            }
        }

        return true;
    }

    public function canEditRole(User $actor, Role $role): bool
    {
        return ! in_array($role->name, self::SYSTEM_ROLES, true) && ! $actor->hasRole($role->name);
    }

    /**
     * May $actor move $role from its current grants to $desired? For every screen whose
     * grants change, the actor must hold every permission involved — both the ones being
     * added and the ones being removed.
     *
     * @param  array<string, list<string>>  $desired  screen => the permissions it should grant
     */
    public function canChangeGrants(User $actor, Role $role, array $desired): bool
    {
        $current = $role->permissions->pluck('name')->all();

        foreach ($desired as $screen => $permissions) {
            $held = array_values(array_filter($current, fn (string $p) => str_starts_with($p, "{$screen}.")));
            sort($held);
            sort($permissions);
            if ($held === $permissions) {
                continue;
            }
            foreach (array_unique(array_merge($held, $permissions)) as $permission) {
                if (! $actor->can($permission)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Refuse a change that would leave no active manager, or no active user who can edit
     * users. The roles and status are what $target will have AFTER the change.
     *
     * @param  list<string>  $newRoles
     *
     * @throws ValidationException
     */
    public function assertKeepsAdmins(User $target, array $newRoles, string $newStatus): void
    {
        $field = $newStatus === 'active' ? 'roles' : 'status';
        $wasActive = $target->status === 'active';
        $staysActive = $newStatus === 'active';

        $wasManager = $wasActive && $target->hasRole(Roles::MANAGER);
        $staysManager = $staysActive && in_array(Roles::MANAGER, $newRoles, true);
        if ($wasManager && ! $staysManager && ! $this->otherActive(fn ($q) => $q->role(Roles::MANAGER), $target)) {
            throw ValidationException::withMessages([$field => __('At least one active manager must remain.')]);
        }

        $couldEditUsers = $wasActive && $target->can('users.edit');
        $canEditUsers = $staysActive && Role::whereIn('name', $newRoles)
            ->whereHas('permissions', fn ($q) => $q->where('name', 'users.edit'))->exists();
        if ($couldEditUsers && ! $canEditUsers && ! $this->otherActive(fn ($q) => $q->permission('users.edit'), $target)) {
            throw ValidationException::withMessages([$field => __('At least one active user must be able to manage users.')]);
        }
    }

    private function otherActive(callable $scope, User $except): bool
    {
        return $scope(User::query())->where('status', 'active')->whereKeyNot($except->id)->exists();
    }
}
