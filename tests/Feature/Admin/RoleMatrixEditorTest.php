<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

class RoleMatrixEditorTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function role(string $name): Role
    {
        return Role::findByName($name);
    }

    public function test_roles_index_lists_the_ten_roles_in_arabic(): void
    {
        $response = $this->actingAs($this->make(Roles::MANAGER))->get(route('roles.index'))->assertOk();

        foreach (Roles::ALL as $role) {
            $response->assertSee(Roles::label($role));
        }
    }

    public function test_matrix_save_grants_cumulative_permissions(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $supervisor = $this->role(Roles::SUPERVISOR);

        $this->actingAs($manager)->put(route('roles.update', $supervisor), [
            'levels' => ['users' => 'edit', 'audit_log' => 'view', 'roles' => 'none'],
        ])->assertRedirect(route('roles.show', $supervisor));

        $this->assertEqualsCanonicalizing(
            ['users.view', 'users.edit', 'audit_log.view'],
            $supervisor->fresh()->permissions->pluck('name')->all(),
        );

        // and back to none
        $this->actingAs($manager)->put(route('roles.update', $supervisor), ['levels' => ['users' => 'none']]);
        $this->assertEqualsCanonicalizing(['audit_log.view'], $supervisor->fresh()->permissions->pluck('name')->all());
    }

    public function test_system_roles_are_locked_for_everybody(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);

        $this->actingAs($manager)->get(route('roles.edit', $this->role(Roles::MANAGER)))->assertForbidden();
        $this->actingAs($manager)->get(route('roles.edit', $this->role(Roles::SYSTEM_ADMIN)))->assertForbidden();
        $this->actingAs($sysadmin)->put(route('roles.update', $this->role(Roles::SYSTEM_ADMIN)), ['levels' => ['import_export' => 'edit']])->assertForbidden();

        $this->assertFalse($this->role(Roles::SYSTEM_ADMIN)->fresh()->hasPermissionTo('import_export.edit'));
    }

    public function test_role_matrix_of_a_held_role_is_read_only(): void
    {
        $person = $this->make(Roles::MANAGER, Roles::SUPERVISOR);

        $this->actingAs($person)->get(route('roles.edit', $this->role(Roles::SUPERVISOR)))->assertForbidden();
        $this->actingAs($person)->get(route('roles.show', $this->role(Roles::SUPERVISOR)))->assertOk();
    }

    public function test_nobody_grants_a_permission_they_do_not_hold(): void
    {
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);
        $staff = $this->role(Roles::STAFF);

        $this->actingAs($sysadmin)->put(route('roles.update', $staff), ['levels' => ['import_export' => 'view']])->assertForbidden();
        $this->assertCount(0, $staff->fresh()->permissions);

        // a screen the system admin does hold is fine
        $this->actingAs($sysadmin)->put(route('roles.update', $staff), ['levels' => ['audit_log' => 'view']])->assertRedirect();
        $this->assertTrue($staff->fresh()->hasPermissionTo('audit_log.view'));
    }

    public function test_a_level_the_screen_does_not_declare_is_refused(): void
    {
        $this->actingAs($this->make(Roles::MANAGER))
            ->put(route('roles.update', $this->role(Roles::SUPERVISOR)), ['levels' => ['audit_log' => 'edit']])
            ->assertSessionHasErrors('levels.audit_log');
    }

    public function test_roles_cannot_be_created_or_deleted(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/roles', ['name' => 'combo'])->assertMethodNotAllowed();
        $this->actingAs($manager)->delete('/roles/'.$this->role(Roles::SUPERVISOR)->id)->assertMethodNotAllowed();
        $this->assertSame(count(Roles::ALL), Role::count());
    }

    public function test_the_role_page_shows_the_matrix_read_only(): void
    {
        $this->actingAs($this->make(Roles::STAFF, Roles::SYSTEM_ADMIN))
            ->get(route('roles.show', $this->role(Roles::SYSTEM_ADMIN)))
            ->assertOk()
            ->assertSee(config('screens.users.label'))
            ->assertSee('data-level="edit"', false);
    }
}
