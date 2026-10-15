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

    public function test_ten_roles_exist(): void
    {
        $this->assertCount(10, Roles::ALL);
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

    public function test_the_eight_business_roles_hold_nothing_yet(): void
    {
        foreach (array_diff(Roles::ALL, [Roles::MANAGER, Roles::SYSTEM_ADMIN]) as $role) {
            $this->assertCount(0, Role::findByName($role)->permissions, $role);
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
