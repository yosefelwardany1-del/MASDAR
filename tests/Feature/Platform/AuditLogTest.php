<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

/**
 * Until Sub-project 2's database-trigger audit, every writer records its own change
 * through AuditTrail — explicitly, never through model events (CLAUDE.md standing rule).
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    public function test_changing_a_user_writes_an_audit_row_with_old_and_new_values(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $target = $this->make(Roles::STAFF);
        $target->forceFill(['name' => 'Old Name'])->save();

        $this->actingAs($manager)->put(route('users.update', $target), [
            'name' => 'New Name', 'email' => $target->email, 'status' => 'active',
            'roles' => [Roles::STAFF, Roles::SALES], 'password' => 'secret-password', 'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $log = AuditLog::where('model_type', User::class)->where('model_id', $target->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($manager->id, $log->user_id);
        $this->assertSame('Old Name', $log->old_values['name']);
        $this->assertSame('New Name', $log->new_values['name']);
        $this->assertEqualsCanonicalizing([Roles::STAFF], $log->old_values['roles']);
        $this->assertEqualsCanonicalizing([Roles::STAFF, Roles::SALES], $log->new_values['roles']);
        $this->assertArrayNotHasKey('password', $log->new_values, 'a password never enters the audit trail');
    }

    public function test_creating_a_user_is_audited(): void
    {
        $this->actingAs($this->make(Roles::MANAGER))->post(route('users.store'), [
            'name' => 'Created', 'email' => 'created@acme.example', 'password' => 'password123', 'password_confirmation' => 'password123',
            'status' => 'active', 'roles' => [Roles::STAFF],
        ]);

        $user = User::where('email', 'created@acme.example')->firstOrFail();
        $this->assertTrue(AuditLog::where('model_type', User::class)->where('model_id', $user->id)->where('action', 'created')->exists());
    }

    public function test_a_role_matrix_change_is_audited(): void
    {
        $supervisor = Role::findByName(Roles::STAFF);

        $this->actingAs($this->make(Roles::MANAGER))->put(route('roles.update', $supervisor), ['levels' => ['users' => 'view']]);

        $log = AuditLog::where('model_type', Role::class)->where('model_id', $supervisor->id)->latest('id')->firstOrFail();
        $this->assertSame([], $log->old_values['permissions']);
        $this->assertSame(['users.view'], $log->new_values['permissions']);
    }

    public function test_my_own_profile_changes_are_audited(): void
    {
        $me = $this->make();
        $me->forceFill(['name' => 'Before'])->save();

        $this->actingAs($me)->put(route('profile.update'), ['name' => 'After', 'email' => $me->email]);
        $this->actingAs($me)->put(route('profile.password'), [
            'current_password' => 'password', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1',
        ]);

        $update = AuditLog::where('model_type', User::class)->where('model_id', $me->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame(['Before', 'After'], [$update->old_values['name'], $update->new_values['name']]);
        $this->assertTrue(AuditLog::where('model_id', $me->id)->where('action', 'password_changed')->exists());
    }

    public function test_audit_log_requires_audit_log_view(): void
    {
        $this->actingAs($this->make(Roles::STAFF))->get(route('audit-logs.index'))->assertForbidden();
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN))->get(route('audit-logs.index'))->assertOk();
    }

    public function test_the_page_renders_old_and_new_values_in_arabic(): void
    {
        $manager = $this->make(Roles::MANAGER);
        AuditLog::create([
            'user_id' => $manager->id, 'action' => 'updated', 'model_type' => User::class, 'model_id' => 7,
            'old_values' => ['name' => 'Before'], 'new_values' => ['name' => 'After'],
        ]);

        $this->actingAs($manager)->get(route('audit-logs.index'))->assertOk()
            ->assertSee('سجل التدقيق')
            ->assertSee('Before')
            ->assertSee('After');
    }
}
