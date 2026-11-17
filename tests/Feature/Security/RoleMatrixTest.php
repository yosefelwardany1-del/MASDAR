<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\Roles;
use App\Security\Screens;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

class RoleMatrixTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    public function test_the_masdar_roles_exist(): void
    {
        $this->assertSame(['manager', 'sales', 'merchandiser', 'warehouse_qc', 'logistics', 'accountant', 'staff', 'system_admin'], Roles::ALL);
        $this->assertEqualsCanonicalizing(Roles::ALL, Role::pluck('name')->all());
    }

    public function test_manager_holds_every_permission(): void
    {
        $this->assertEqualsCanonicalizing(
            Screens::allPermissions(),
            Role::findByName(Roles::MANAGER)->permissions->pluck('name')->all(),
        );
    }

    public function test_system_admin_holds_only_admin_screens(): void
    {
        $expected = array_merge(
            Screens::grantsFor('users', 'edit'),
            Screens::grantsFor('roles', 'edit'),
            Screens::grantsFor('settings', 'edit'),
            Screens::grantsFor('audit_log', 'view'),
        );

        $held = Role::findByName(Roles::SYSTEM_ADMIN)->permissions->pluck('name')->all();

        $this->assertEqualsCanonicalizing($expected, $held);
        $this->assertNotContains('import_export.view', $held);
    }

    public function test_staff_starts_with_nothing_and_business_roles_hold_their_defaults(): void
    {
        $this->assertCount(0, Role::findByName(Roles::STAFF)->permissions);

        foreach ([Roles::SALES, Roles::MERCHANDISER, Roles::WAREHOUSE_QC, Roles::LOGISTICS, Roles::ACCOUNTANT] as $role) {
            $this->assertEqualsCanonicalizing(Roles::defaultGrants($role), Role::findByName($role)->permissions->pluck('name')->all(), $role);
        }
    }

    public function test_no_business_role_manages_users_roles_or_settings(): void
    {
        foreach ([Roles::SALES, Roles::MERCHANDISER, Roles::WAREHOUSE_QC, Roles::LOGISTICS, Roles::ACCOUNTANT, Roles::STAFF] as $role) {
            $held = Role::findByName($role)->permissions->pluck('name')->all();
            foreach (['users.view', 'roles.view', 'settings.view', 'audit_log.view'] as $admin) {
                $this->assertNotContains($admin, $held, "{$role} must not hold {$admin}");
            }
        }
    }

    public function test_levels_are_cumulative(): void
    {
        $this->assertSame(['users.view'], Screens::grantsFor('users', 'view'));
        $this->assertSame(['users.view', 'users.edit'], Screens::grantsFor('users', 'edit'));
        $this->assertSame(['audit_log.view'], Screens::grantsFor('audit_log', 'view'));
    }

    public function test_two_roles_union_takes_the_higher_level(): void
    {
        Role::create(['name' => 'a'])->givePermissionTo(Screens::grantsFor('users', 'view'));
        Role::create(['name' => 'b'])->givePermissionTo(Screens::grantsFor('users', 'edit'));

        $user = User::factory()->create();
        $user->assignRole(['a', 'b']);

        $this->assertSame('edit', Screens::levelOf($user, 'users'));
        $this->assertTrue(Screens::allows($user, 'users', 'edit'));
        $this->assertTrue($user->can('users.edit'));
    }

    public function test_a_user_with_no_grant_has_no_level(): void
    {
        $user = User::factory()->create();

        $this->assertNull(Screens::levelOf($user, 'users'));
        $this->assertFalse(Screens::allows($user, 'users', 'view'));
    }

    public function test_no_gate_bypass(): void
    {
        Role::create(['name' => 'Super Admin']);
        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        $this->assertFalse($user->can('users.view'));
        $this->assertFalse($user->can('anything-at-all'));
    }

    public function test_reseeding_keeps_matrix_edits(): void
    {
        Role::findByName(Roles::SYSTEM_ADMIN)->revokePermissionTo('settings.edit');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertFalse(Role::findByName(Roles::SYSTEM_ADMIN)->fresh()->hasPermissionTo('settings.edit'));
        $this->assertTrue(Role::findByName(Roles::SYSTEM_ADMIN)->fresh()->hasPermissionTo('settings.view'));
    }

    public function test_reseeding_adds_a_new_screen_permission_without_regranting_others(): void
    {
        config(['screens.extra_screen' => [
            'label' => 'شاشة', 'route' => 'dashboard', 'group' => 'admin', 'icon' => 'bi-square',
            'levels' => ['view'], 'signed_in' => false,
        ]]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertDatabaseHas('permissions', ['name' => 'extra_screen.view']);
        // A new screen reaches the roles whose defaults include it (the manager holds all)…
        $this->assertTrue(Role::findByName(Roles::MANAGER)->fresh()->hasPermissionTo('extra_screen.view'));
        // …and nobody else.
        $this->assertFalse(Role::findByName(Roles::SYSTEM_ADMIN)->fresh()->hasPermissionTo('extra_screen.view'));
    }
}
