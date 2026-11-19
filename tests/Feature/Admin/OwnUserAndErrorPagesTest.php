<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B24: Edit shows only where it opens, yourself is on My profile, and errors are MASDAR's pages with a way back. */
class OwnUserAndErrorPagesTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_my_own_row_points_to_my_profile_and_edit_on_myself_does_not_403(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en', 'name' => 'Ana Manager'])->assignRole(Roles::MANAGER);
        $admin = User::factory()->create(['locale' => 'en', 'name' => 'Sam Support'])->assignRole(Roles::SYSTEM_ADMIN);

        $page = $this->actingAs($manager)->get(route('users.index'))->assertOk();
        $page->assertDontSee('href="'.route('users.edit', $manager).'"', false)->assertSee('href="'.route('profile').'"', false);
        $this->actingAs($manager)->get(route('users.edit', $manager))->assertRedirect(route('profile'))
            ->assertSessionHas('info', 'Your own details are on My profile. Another administrator changes your roles.');

        // The system administrator holds no business screen, so a manager's row offers no Edit to them.
        $this->actingAs($admin)->get(route('users.index'))->assertOk()->assertDontSee('href="'.route('users.edit', $manager).'"', false);
        $this->actingAs($admin)->get(route('users.edit', $manager))->assertForbidden()
            ->assertSee('You may not open this page')->assertSee('Home')->assertDontSee('403 | FORBIDDEN');
    }

    public function test_a_missing_page_is_an_app_page_with_a_way_back(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $this->actingAs($manager)->from(route('users.index'))->get('/clients/999999')->assertNotFound()
            ->assertSee('This page was not found')->assertSee('Go back')->assertSee(route('users.index'), false);
    }
}
