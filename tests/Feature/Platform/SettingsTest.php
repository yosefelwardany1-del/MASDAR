<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    public function test_settings_edit_requires_settings_edit(): void
    {
        $this->actingAs($this->make(Roles::STOREKEEPER))->get(route('settings.edit'))->assertForbidden();
        $this->actingAs($this->make(Roles::STOREKEEPER))->put(route('settings.update'), ['company_name_ar' => 'x', 'company_name' => 'x'])->assertForbidden();
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN))->get(route('settings.edit'))->assertOk();
    }

    public function test_reading_settings_writes_nothing(): void
    {
        $this->assertSame('القدس', Setting::current()->company_name_ar);
        $this->assertSame(0, Setting::count(), 'a read never creates the row');
    }

    public function test_brand_shows_company_name_ar_and_the_change_is_audited(): void
    {
        $admin = $this->make(Roles::SYSTEM_ADMIN);

        $this->actingAs($admin)->put(route('settings.update'), [
            'company_name_ar' => 'شركة القدس للنشر والتوزيع', 'company_name' => 'Al Quds Co.',
        ])->assertRedirect(route('settings.edit'));

        $this->assertSame('شركة القدس للنشر والتوزيع', Setting::current()->company_name_ar);
        $this->actingAs($admin)->get('/')->assertSee('شركة القدس للنشر والتوزيع');
        $this->assertTrue(AuditLog::where('model_type', Setting::class)->where('action', 'updated')->exists());
    }

    public function test_a_logo_is_stored_privately_and_served_through_a_route(): void
    {
        Storage::fake('local');
        $admin = $this->make(Roles::SYSTEM_ADMIN);

        $this->actingAs($admin)->post(route('settings.logo.store'), ['logo' => UploadedFile::fake()->image('logo.png', 120, 120)])->assertRedirect();

        $setting = Setting::current();
        $this->assertNotNull($setting->logo);
        Storage::disk('local')->assertExists($setting->logo);
        $this->actingAs($admin)->get(route('settings.logo'))->assertOk();

        $this->actingAs($admin)->post(route('settings.logo.store'), [
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');
    }
}
