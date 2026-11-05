<?php

namespace Tests\Feature\Auth;

use App\Models\ErpNotification;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Security\Roles;
use App\Services\Auth\SsoSignIn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

/**
 * Sign in with Google — off by default, on once the client has a Workspace domain.
 *
 * Who gets a session and what changes on the account when they do: only the allowed
 * domains; an existing account is matched by address and NOTHING about it changes; a new
 * address is parked as 'pending' with no role until somebody who may edit users assigns
 * one; a person with two mailboxes is one account.
 */
class GoogleSsoSignInTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private const STATE = 'state-token-for-tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sso.enabled' => true,
            'sso.client_id' => 'test-client-id',
            'sso.client_secret' => 'test-client-secret',
            'sso.allowed_domains' => ['acme.example', 'acme-export.example'],
            'sso.passwords_retired' => false,
            'sso.breakglass_emails' => [],
        ]);
    }

    private function google(array $claims = [], ?string $tokenError = null): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => $tokenError
                ? Http::response(['error' => $tokenError], 400)
                : Http::response(['access_token' => 'at-123', 'id_token' => 'x', 'expires_in' => 3599, 'token_type' => 'Bearer']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response(array_merge([
                'sub' => '1234567890',
                'email' => 'omar.hassan@acme.example',
                'email_verified' => true,
                'name' => 'Omar Hassan',
                'hd' => 'acme.example',
            ], $claims)),
        ]);
    }

    private function googleCallback(string $state = self::STATE, string $code = 'auth-code')
    {
        return $this->withSession(['sso_google_state' => self::STATE])
            ->get(route('sso.google.callback', ['state' => $state, 'code' => $code]));
    }

    private function staff(string $email, ?string $role = Roles::STAFF, string $status = 'active'): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => bcrypt('secret123'), 'status' => $status]);
        if ($role) {
            $user->assignRole($role);
        }

        return $user;
    }

    // ---- the round trip ---------------------------------------------------------

    public function test_the_login_page_offers_google_when_it_is_configured(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('المتابعة باستخدام Google')
            ->assertSee(route('sso.google'), false);
    }

    public function test_the_button_disappears_when_no_client_is_configured(): void
    {
        config(['sso.client_id' => null, 'sso.client_secret' => null]);

        $this->get(route('login'))->assertOk()->assertDontSee('المتابعة باستخدام Google');
    }

    public function test_the_redirect_asks_google_for_identity_only(): void
    {
        $response = $this->get(route('sso.google'))->assertRedirect();
        $location = $response->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $q);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $location);
        $this->assertSame('test-client-id', $q['client_id']);
        $this->assertSame(route('sso.google.callback'), $q['redirect_uri']);
        $this->assertSame('openid email profile', $q['scope']);
        $this->assertSame('online', $q['access_type'], 'no refresh token: nothing to store');
        $this->assertSame($q['state'], session('sso_google_state'));
    }

    public function test_a_state_mismatch_is_refused_before_google_is_called(): void
    {
        Http::fake();

        $this->googleCallback(state: 'forged')->assertRedirect(route('login'))->assertSessionHasErrors('sso');

        Http::assertNothingSent();
        $this->assertGuest();
    }

    public function test_a_cancelled_consent_goes_back_to_login(): void
    {
        Http::fake();

        $this->withSession(['sso_google_state' => self::STATE])
            ->get(route('sso.google.callback', ['state' => self::STATE, 'error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('sso');

        $this->assertGuest();
    }

    public function test_a_failed_token_exchange_is_a_visible_error_not_a_session(): void
    {
        $this->google([], tokenError: 'invalid_grant');

        $this->googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('sso');

        $this->assertGuest();
    }

    // ---- who may sign in --------------------------------------------------------

    public function test_an_outside_domain_is_refused_and_creates_nothing(): void
    {
        $this->google(['email' => 'someone@gmail.com', 'hd' => null]);

        $this->googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('sso');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'someone@gmail.com']);
        $this->assertSame(0, UserSsoIdentity::count());
    }

    public function test_an_address_google_has_not_verified_is_refused(): void
    {
        $this->google(['email_verified' => false]);

        $this->googleCallback()->assertSessionHasErrors('sso');

        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'omar.hassan@acme.example']);
    }

    public function test_every_allowed_domain_gets_in(): void
    {
        foreach (['a@acme.example', 'b@acme-export.example'] as $email) {
            $this->staff($email);
            $this->google(['email' => $email, 'sub' => 'sub-'.$email]);

            $this->googleCallback()->assertRedirect(route('dashboard'));
            $this->assertAuthenticated();

            $this->post(route('logout'));
        }
    }

    // ---- existing accounts ------------------------------------------------------

    public function test_an_existing_account_signs_in_and_nothing_about_it_changes(): void
    {
        $user = $this->staff('omar.hassan@acme.example', Roles::SUPERVISOR);
        $rolesBefore = $user->getRoleNames()->all();
        $passwordBefore = $user->password;
        $this->google();

        $this->googleCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $fresh = $user->fresh();
        $this->assertSame($rolesBefore, $fresh->getRoleNames()->all(), 'the roles must not change');
        $this->assertSame($passwordBefore, $fresh->password, 'the password must not change');
        $this->assertSame('active', $fresh->status);
        $this->assertNotNull($fresh->last_login);
        $this->assertDatabaseHas('user_sso_identities', [
            'user_id' => $user->id, 'email' => 'omar.hassan@acme.example', 'google_sub' => '1234567890',
        ]);
    }

    public function test_a_google_sign_in_is_remembered_past_the_session(): void
    {
        $user = $this->staff('omar.hassan@acme.example');
        $this->google();

        $response = $this->googleCallback()->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->remember_token);
        $remember = collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
        $this->assertNotNull($remember, 'the remember cookie must be set');
        $this->assertGreaterThan(time() + 60 * 60 * 24 * 29, $remember->getExpiresTime(), 'about thirty days');

        config(['sso.remember' => false]);
        Auth::logout();
        $this->google();
        $response = $this->googleCallback()->assertRedirect(route('dashboard'));
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
    }

    public function test_a_paused_account_is_signed_out_and_other_devices_drop_too(): void
    {
        $user = $this->staff('omar.hassan@acme.example');
        $this->google();
        $this->googleCallback();
        $token = $user->fresh()->remember_token;
        $this->get(route('dashboard'))->assertOk();

        $user->forceFill(['status' => 'inactive'])->save();
        $this->app['auth']->forgetGuards();

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertNotSame($token, $user->fresh()->remember_token, 'other devices are signed out too');
    }

    public function test_the_match_ignores_the_case_of_the_stored_address(): void
    {
        $user = $this->staff('Basma.Haggag@acme.example');
        $this->google(['email' => 'basma.haggag@acme.example', 'sub' => 'sub-basma']);

        $this->googleCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count(), 'no duplicate account');
    }

    public function test_an_inactive_account_is_refused_even_with_a_valid_google_login(): void
    {
        $this->staff('omar.hassan@acme.example', status: 'inactive');
        $this->google();

        $this->googleCallback()->assertRedirect(route('login'))->assertSessionHasErrors('sso');

        $this->assertGuest();
    }

    // ---- new addresses: pending -------------------------------------------------

    public function test_a_new_address_is_parked_as_pending_and_the_user_admins_are_told(): void
    {
        $manager = $this->staff('manager@acme.example', Roles::MANAGER);
        $sysadmin = $this->staff('support@acme.example', Roles::SYSTEM_ADMIN);
        $staff = $this->staff('store@acme.example', Roles::STAFF);
        $this->google(['email' => 'new.hire@acme-export.example', 'name' => 'New Hire', 'sub' => 'sub-new']);

        $this->googleCallback()->assertRedirect(route('sso.pending'));

        $this->assertGuest();
        $user = User::where('email', 'new.hire@acme-export.example')->first();
        $this->assertNotNull($user);
        $this->assertSame('pending', $user->status);
        $this->assertSame('New Hire', $user->name);
        $this->assertSame([], $user->getRoleNames()->all(), 'no role until a person assigns one');
        $this->assertFalse($user->canSignIn());

        // Everybody who may edit users hears about it; nobody else does.
        foreach ([$manager, $sysadmin] as $admin) {
            $notice = ErpNotification::where('user_id', $admin->id)->latest('id')->first();
            $this->assertNotNull($notice, 'a user admin must be notified');
            $this->assertStringContainsString('new.hire@acme-export.example', $notice->message);
        }
        $this->assertSame(0, ErpNotification::where('user_id', $staff->id)->count());

        $this->get(route('sso.pending'))->assertOk()->assertSee('new.hire@acme-export.example');
    }

    public function test_signing_in_again_while_pending_does_not_duplicate_or_renotify(): void
    {
        $manager = $this->staff('manager@acme.example', Roles::MANAGER);
        $this->google(['email' => 'new.hire@acme-export.example', 'sub' => 'sub-new']);

        $this->googleCallback()->assertRedirect(route('sso.pending'));
        $this->googleCallback()->assertRedirect(route('sso.pending'));

        $this->assertSame(1, User::where('email', 'new.hire@acme-export.example')->count());
        $this->assertSame(1, ErpNotification::where('user_id', $manager->id)->count(), 'one arrival, one notice');
        $this->assertGuest();
    }

    public function test_the_pending_page_needs_a_pending_sign_in_behind_it(): void
    {
        $this->get(route('sso.pending'))->assertRedirect(route('login'));
    }

    // ---- two addresses, one person ----------------------------------------------

    public function test_a_linked_second_address_signs_in_as_the_same_account(): void
    {
        $admin = $this->staff('manager@acme.example', Roles::MANAGER);
        $yosef = $this->staff('yosef@acme-export.example');

        app(SsoSignIn::class)->linkAddress($yosef, 'Yosef@Acme.Example', $admin);

        $this->google(['email' => 'yosef@acme.example', 'sub' => 'sub-yosef']);
        $this->googleCallback()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($yosef);
        $this->assertSame(2, User::count(), 'no second account for the second mailbox');
    }

    // ---- passwords retired ------------------------------------------------------

    public function test_retired_passwords_are_refused_for_our_domains_only(): void
    {
        config(['sso.passwords_retired' => true, 'sso.breakglass_emails' => ['admin@acme.example']]);
        $this->staff('omar@acme.example');
        $this->staff('agent@partner.test');
        $this->staff('admin@acme.example', Roles::SYSTEM_ADMIN);

        $this->post(route('login.post'), ['email' => 'omar@acme.example', 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->post(route('login.post'), ['email' => 'agent@partner.test', 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticated();
        $this->post(route('logout'));

        $this->post(route('login.post'), ['email' => 'admin@acme.example', 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_a_retired_password_is_refused_without_being_checked(): void
    {
        config(['sso.passwords_retired' => true]);
        $this->staff('omar@acme.example');

        $this->post(route('login.post'), ['email' => 'omar@acme.example', 'password' => 'secret123']);
        $right = session('errors')?->first('email');
        $this->post(route('login.post'), ['email' => 'omar@acme.example', 'password' => 'nope']);
        $wrong = session('errors')?->first('email');

        $this->assertSame('لم تعد كلمات المرور مستخدمة لهذه الحسابات. سجّل الدخول بحساب Google.', $right);
        $this->assertSame($right, $wrong, 'right and wrong passwords get the same answer');
        $this->assertGuest();
    }

    public function test_the_login_page_folds_the_password_form_away_when_passwords_are_retired(): void
    {
        config(['sso.passwords_retired' => true]);

        $this->get(route('login'))->assertOk()
            ->assertSee('المتابعة باستخدام Google')
            ->assertSee('دخول طارئ بكلمة المرور');
    }

    public function test_the_login_page_offers_the_password_form_plainly_when_nothing_is_retired(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('أو بكلمة المرور')
            ->assertDontSee('دخول طارئ بكلمة المرور');
    }

    public function test_password_login_works_when_sso_is_on_but_no_domain_is_configured(): void
    {
        config(['sso.passwords_retired' => true, 'sso.allowed_domains' => []]);
        $user = $this->staff('omar@acme.example');

        $this->post(route('login.post'), ['email' => 'omar@acme.example', 'password' => 'secret123'])->assertRedirect();

        $this->assertAuthenticatedAs($user);
    }

    // ---- the switch -------------------------------------------------------------

    public function test_google_button_hidden_and_routes_404_when_disabled(): void
    {
        config(['sso.enabled' => false]);

        $this->get(route('sso.google'))->assertNotFound();
        $this->get(route('sso.google.callback'))->assertNotFound();
        $this->get(route('login'))->assertOk()->assertDontSee('المتابعة باستخدام Google');
    }

    public function test_sign_in_with_google_is_off_by_default(): void
    {
        // Read the shipped file, not the config this test class switched on.
        $this->assertFalse((require base_path('config/sso.php'))['enabled']);
    }

    public function test_the_rules_are_pure_and_testable_without_google(): void
    {
        $rules = app(SsoSignIn::class);

        $this->assertTrue($rules->isOrgAddress('X@Acme-Export.Example'));
        $this->assertFalse($rules->isOrgAddress('x@elsewhere.test'));
        $this->assertFalse($rules->isOrgAddress('not-an-email'));
        $this->assertFalse($rules->passwordRetiredFor('x@acme.example'), 'switch is off');
    }
}
