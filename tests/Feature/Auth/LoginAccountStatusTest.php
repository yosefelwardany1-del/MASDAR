<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A correct password is not, on its own, permission to hold a session: the account
 * must be active too. 'pending' (a Google sign-in nobody has assigned) and 'inactive'
 * are refused, and the refusal leaves no session behind.
 */
class LoginAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email, string $status): User
    {
        return User::create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret123'), 'status' => $status]);
    }

    public function test_an_active_account_can_sign_in(): void
    {
        $user = $this->user('active@t.test', 'active');

        $this->post(route('login.post'), ['email' => 'active@t.test', 'password' => 'secret123'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login);
    }

    public function test_an_inactive_account_is_refused_despite_the_right_password(): void
    {
        $this->user('inactive@t.test', 'inactive');

        $this->post(route('login.post'), ['email' => 'inactive@t.test', 'password' => 'secret123'])
            ->assertRedirect()
            ->assertSessionHasErrors(['email' => 'هذا الحساب غير مفعّل. تواصل مع مسؤول النظام.']);

        $this->assertGuest();
    }

    public function test_the_refusal_leaves_no_session_to_reuse(): void
    {
        $this->user('inactive2@t.test', 'inactive');

        $this->post(route('login.post'), ['email' => 'inactive2@t.test', 'password' => 'secret123']);

        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_pending_account_is_refused_despite_the_right_password(): void
    {
        $this->user('pending@t.test', 'pending');

        $this->post(route('login.post'), ['email' => 'pending@t.test', 'password' => 'secret123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_status_is_refused_by_default(): void
    {
        $user = $this->user('someday@t.test', 'active');
        $this->assertTrue($user->canSignIn());

        $user->status = 'suspended';
        $this->assertFalse($user->canSignIn(), 'a status other than active must not sign in');
    }

    public function test_the_status_column_refuses_values_outside_the_list(): void
    {
        $user = $this->user('constrained@t.test', 'active');

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['status' => 'suspended']);
    }

    public function test_a_wrong_password_fails_the_ordinary_way(): void
    {
        $this->user('active2@t.test', 'active');

        $this->post(route('login.post'), ['email' => 'active2@t.test', 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.']);

        $this->assertGuest();
    }

    public function test_a_paused_account_is_signed_out_on_its_next_request(): void
    {
        $user = $this->user('paused@t.test', 'active');
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->forceFill(['status' => 'inactive'])->save();
        $this->app['auth']->forgetGuards();

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_the_login_page_is_arabic_rtl(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('تسجيل الدخول');
    }

    public function test_logout_ends_the_session(): void
    {
        $user = $this->user('out@t.test', 'active');

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
