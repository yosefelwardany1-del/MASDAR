<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

/**
 * A new password ends every OTHER sign-in. A password is reset because it may have leaked;
 * if the browser that used it keeps its session (7 days) or its "remember me" cookie (30),
 * the reset changed nothing for whoever holds them.
 */
class PasswordChangeEndsSessionsTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    protected function setUp(): void
    {
        parent::setUp();
        // Production keeps sessions in the database (render.yaml); that is what is cleared.
        config(['session.driver' => 'database']);
    }

    private function openSession(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9',
            'user_agent' => 'elsewhere', 'payload' => base64_encode('a:0:{}'), 'last_activity' => now()->timestamp,
        ]);
    }

    public function test_an_admin_password_reset_signs_the_user_out_everywhere(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $target = User::factory()->create(['remember_token' => 'stolen-cookie-token']);
        $this->openSession($target, 'attacker-session');
        $bystander = User::factory()->create();
        $this->openSession($bystander, 'bystander-session');

        $this->actingAs($manager)->put(route('users.update', $target), [
            'name' => $target->name, 'email' => $target->email, 'status' => 'active',
            'password' => 'a-new-password', 'password_confirmation' => 'a-new-password',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('a-new-password', $target->fresh()->password));
        $this->assertNotSame('stolen-cookie-token', $target->fresh()->remember_token, 'the remember-me cookie must stop working');
        $this->assertFalse(DB::table('sessions')->where('id', 'attacker-session')->exists(), 'their open sessions must end');
        $this->assertTrue(DB::table('sessions')->where('id', 'bystander-session')->exists(), 'nobody else is signed out');
    }

    public function test_an_edit_without_a_new_password_signs_nobody_out(): void
    {
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $target = User::factory()->create(['remember_token' => 'kept-token']);
        $this->openSession($target, 'their-session');

        $this->actingAs($manager)->put(route('users.update', $target), [
            'name' => 'Renamed', 'email' => $target->email, 'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('kept-token', $target->fresh()->remember_token);
        $this->assertTrue(DB::table('sessions')->where('id', 'their-session')->exists());
    }

    public function test_changing_my_own_password_ends_my_other_sessions_but_keeps_this_one(): void
    {
        $me = User::factory()->create(['password' => Hash::make('old-password'), 'remember_token' => 'old-token']);
        $this->openSession($me, 'my-phone');

        $this->actingAs($me)->put(route('profile.password'), [
            'current_password' => 'old-password',
            'password' => 'a-new-password', 'password_confirmation' => 'a-new-password',
        ])->assertRedirect(route('profile'));

        $this->assertNotSame('old-token', $me->fresh()->remember_token);
        $this->assertFalse(DB::table('sessions')->where('id', 'my-phone')->exists(), 'my other devices are signed out');
        $this->assertAuthenticatedAs($me);
        $this->get(route('profile'))->assertOk();
    }
}
