<?php

namespace Tests\Feature\Platform;

use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private function label(string $screen): string
    {
        return config("screens.{$screen}.label");
    }

    public function test_home_renders_rtl_arabic(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);

        $this->actingAs($manager)->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('lang="ar"', false)
            ->assertSee('مرحباً');   // the interface strings come from lang/ar.json
    }

    public function test_manager_sidebar_lists_every_screen(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);

        $response = $this->actingAs($manager)->get('/')->assertOk();

        foreach (['home', 'users', 'roles', 'settings', 'audit_log', 'import_export'] as $screen) {
            $response->assertSee($this->label($screen));
        }
    }

    public function test_system_admin_sidebar_has_only_its_screens(): void
    {
        $admin = User::factory()->create()->assignRole(Roles::SYSTEM_ADMIN);

        $response = $this->actingAs($admin)->get('/')->assertOk();

        foreach (['users', 'roles', 'settings', 'audit_log'] as $screen) {
            $response->assertSee($this->label($screen));
        }
        $response->assertDontSee($this->label('import_export'));
    }

    public function test_user_without_roles_lands_on_home(): void
    {
        $nobody = User::factory()->create();

        $response = $this->actingAs($nobody)->get('/')->assertOk();

        $response->assertSee($this->label('home'));
        foreach (['users', 'roles', 'settings', 'audit_log', 'import_export'] as $screen) {
            $response->assertDontSee($this->label($screen));
        }
    }
}
