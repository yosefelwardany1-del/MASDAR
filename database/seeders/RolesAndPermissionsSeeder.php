<?php

namespace Database\Seeders;

use App\Security\Roles;
use App\Security\Screens;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Runs on every deploy (docker/entrypoint.sh), so it must never undo the role editor:
 *  - a role it CREATES gets its default grants;
 *  - a permission it CREATES (a screen added by a new sub-project) goes to every role whose
 *    defaults include it;
 *  - a grant on a permission that already existed is never added or removed.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = [];
        foreach (Screens::allPermissions() as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            if ($permission->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        foreach (Roles::ALL as $name) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            $defaults = Roles::defaultGrants($name);

            $grant = $role->wasRecentlyCreated ? $defaults : array_values(array_intersect($defaults, $created));
            if ($grant !== []) {
                $role->givePermissionTo($grant);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
