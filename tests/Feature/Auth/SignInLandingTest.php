<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Where a sign-in lands. The home page needs sign-in only, so everybody may land there;
 * a page that bounced a guest to the login form is replayed afterwards, and its own
 * permission middleware judges it.
 */
class SignInLandingTest extends TestCase
{
    use RefreshDatabase;

    private function login(): TestResponse
    {
        User::create(['name' => 'Basma', 'email' => 'basma@t.test', 'password' => bcrypt('secret123'), 'status' => 'active']);

        return $this->post(route('login.post'), ['email' => 'basma@t.test', 'password' => 'secret123']);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_a_user_with_no_roles_lands_on_home_and_it_opens(): void
    {
        $this->login()->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_bounced_page_is_replayed_after_sign_in(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));

        $this->login()->assertRedirect(route('notifications.index'));
    }

    public function test_the_login_page_itself_is_never_replayed(): void
    {
        $this->withSession(['url.intended' => route('login')]);

        $this->login()->assertRedirect(route('dashboard'));
    }

    public function test_an_already_signed_in_user_is_sent_home_from_the_login_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('login'))->assertRedirect(route('dashboard'));
    }
}
