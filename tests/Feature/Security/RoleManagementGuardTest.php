<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Security\RoleManagementGuard;
use App\Security\Roles;
use App\Security\Screens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

/**
 * No privilege escalation through user management: nobody changes their own roles, only a
 * manager makes a manager, only a system admin makes a system admin, nobody assigns a role
 * carrying permissions they do not hold, and the last active manager / user admin stays.
 */
class RoleManagementGuardTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function newUser(array $roles): array
    {
        return [
            'name' => 'New Person', 'email' => 'new.'.uniqid().'@acme.example',
            'password' => 'password123', 'password_confirmation' => 'password123',
            'status' => 'active', 'roles' => $roles,
        ];
    }

    private function guard(): RoleManagementGuard
    {
        return app(RoleManagementGuard::class);
    }

    public function test_nobody_changes_their_own_roles(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->assertFalse($this->guard()->canManageUser($manager, $manager));
        $this->actingAs($manager)->put(route('users.update', $manager), [
            'name' => $manager->name, 'email' => $manager->email, 'status' => 'active',
            'roles' => [Roles::MANAGER, Roles::SYSTEM_ADMIN],
        ])->assertForbidden();

        $this->assertFalse($manager->fresh()->hasRole(Roles::SYSTEM_ADMIN));
    }

    public function test_only_a_manager_grants_manager(): void
    {
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($sysadmin)->post(route('users.store'), $this->newUser([Roles::MANAGER]))->assertForbidden();
        $this->actingAs($manager)->post(route('users.store'), $this->newUser([Roles::MANAGER]))->assertRedirect(route('users.index'));
    }

    public function test_only_a_system_admin_grants_system_admin(): void
    {
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post(route('users.store'), $this->newUser([Roles::SYSTEM_ADMIN]))->assertForbidden();
        $this->actingAs($sysadmin)->post(route('users.store'), $this->newUser([Roles::SYSTEM_ADMIN]))->assertRedirect(route('users.index'));
    }

    public function test_a_role_carrying_permissions_the_actor_lacks_cannot_be_assigned(): void
    {
        Role::findByName(Roles::SALES)->givePermissionTo(Screens::grantsFor('import_export', 'view'));
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);

        $this->assertFalse($this->guard()->canAssignRole($sysadmin, Roles::SALES));
        $this->actingAs($sysadmin)->post(route('users.store'), $this->newUser([Roles::SALES]))->assertForbidden();
        $this->assertTrue($this->guard()->canAssignRole($sysadmin, Roles::STAFF), 'a role with nothing the actor lacks is fine');
    }

    public function test_a_system_admin_cannot_edit_a_manager_account(): void
    {
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);
        $manager = $this->make(Roles::MANAGER);

        $this->assertFalse($this->guard()->canManageUser($sysadmin, $manager));
        $this->actingAs($sysadmin)->put(route('users.update', $manager), [
            'name' => $manager->name, 'email' => $manager->email, 'status' => 'inactive', 'roles' => [],
        ])->assertForbidden();
        $this->assertSame('active', $manager->fresh()->status);
    }

    public function test_last_active_manager_cannot_lose_the_role(): void
    {
        $only = $this->make(Roles::MANAGER);

        try {
            $this->guard()->assertKeepsAdmins($only, [Roles::STAFF], 'active');
            $this->fail('removing the last active manager was allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roles', $e->errors());
        }

        try {
            $this->guard()->assertKeepsAdmins($only, [Roles::MANAGER], 'inactive');
            $this->fail('deactivating the last active manager was allowed');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->make(Roles::MANAGER);
        $this->guard()->assertKeepsAdmins($only->fresh(), [Roles::STAFF], 'active');
        $this->addToAssertionCount(1);
    }

    public function test_last_user_who_can_edit_users_cannot_be_removed_or_deactivated(): void
    {
        // No manager yet (a fresh install): the system admin is the only one who can edit users.
        $admin = $this->make(Roles::SYSTEM_ADMIN);

        foreach ([[[Roles::STAFF], 'active', 'roles'], [[Roles::SYSTEM_ADMIN], 'inactive', 'status']] as [$roles, $status, $field]) {
            try {
                $this->guard()->assertKeepsAdmins($admin, $roles, $status);
                $this->fail('the last user admin was removed');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }

        $this->make(Roles::SYSTEM_ADMIN);
        $this->guard()->assertKeepsAdmins($admin->fresh(), [Roles::STAFF], 'active');
        $this->addToAssertionCount(1);
    }

    public function test_users_cannot_be_deleted_only_deactivated(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $victim = $this->make(Roles::STAFF);

        $this->actingAs($manager)->delete('/users/'.$victim->id)->assertMethodNotAllowed();
        $this->assertNotNull($victim->fresh());
    }
}
