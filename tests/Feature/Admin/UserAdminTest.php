<?php

namespace Tests\Feature\Admin;

use App\Models\ErpNotification;
use App\Models\User;
use App\Models\UserSsoIdentity;
use App\Security\Roles;
use App\Services\Notifications\NotificationType;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\SeedsRoles;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private const STATE = 'state-token-for-tests';

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function enableGoogle(): void
    {
        config([
            'sso.enabled' => true, 'sso.client_id' => 'id', 'sso.client_secret' => 'secret',
            'sso.allowed_domains' => ['alquds.example'], 'sso.passwords_retired' => false,
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at']),
            'openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'sub-new', 'email' => 'new.hire@alquds.example', 'email_verified' => true, 'name' => 'New Hire',
            ]),
        ]);
    }

    private function googleCallback()
    {
        return $this->withSession(['sso_google_state' => self::STATE])
            ->get(route('sso.google.callback', ['state' => self::STATE, 'code' => 'c']));
    }

    // ---- creating and listing ----------------------------------------------------

    public function test_user_can_hold_several_roles(): void
    {
        $this->actingAs($this->make(Roles::MANAGER))->post(route('users.store'), [
            'name' => 'سعيد', 'email' => 'saeed@alquds.example', 'password' => 'password123', 'password_confirmation' => 'password123',
            'status' => 'active', 'roles' => [Roles::STOREKEEPER, Roles::SHIPPING_OFFICER],
        ])->assertRedirect(route('users.index'));

        $saeed = User::where('email', 'saeed@alquds.example')->firstOrFail();
        $this->assertEqualsCanonicalizing([Roles::STOREKEEPER, Roles::SHIPPING_OFFICER], $saeed->getRoleNames()->all());
    }

    public function test_users_index_shows_roles_in_arabic_and_never_for_no_sign_in(): void
    {
        $this->make(Roles::STOREKEEPER)->forceFill(['email' => 'store@alquds.example'])->save();

        $this->actingAs($this->make(Roles::MANAGER))->get(route('users.index'))->assertOk()
            ->assertSee('store@alquds.example')
            ->assertSee(Roles::label(Roles::STOREKEEPER))
            ->assertSee('أبداً');
    }

    public function test_index_search_and_status_filter_run_on_the_server(): void
    {
        $this->make()->forceFill(['email' => 'findme@alquds.example', 'status' => 'inactive'])->save();
        $this->make()->forceFill(['email' => 'other@alquds.example'])->save();
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->get(route('users.index', ['search' => 'findme']))->assertSee('findme@alquds.example')->assertDontSee('other@alquds.example');
        $this->actingAs($manager)->get(route('users.index', ['status' => 'inactive']))->assertSee('findme@alquds.example')->assertDontSee('other@alquds.example');
    }

    public function test_user_screens_need_the_users_permissions(): void
    {
        $storekeeper = $this->make(Roles::STOREKEEPER);

        $this->actingAs($storekeeper)->get(route('users.index'))->assertForbidden();
        $this->actingAs($storekeeper)->get(route('users.create'))->assertForbidden();
    }

    public function test_user_create_command_assigns_roles(): void
    {
        $this->artisan('user:create', ['email' => 'first@alquds.example', 'name' => 'First Manager', '--role' => [Roles::MANAGER], '--generate' => true])
            ->assertSuccessful();

        $user = User::where('email', 'first@alquds.example')->firstOrFail();
        $this->assertTrue($user->hasRole(Roles::MANAGER));
        $this->assertSame('active', $user->status);

        $this->artisan('user:create', ['email' => 'first@alquds.example', 'name' => 'Again', '--generate' => true])->assertFailed();
        $this->artisan('user:create', ['email' => 'x@alquds.example', 'name' => 'X', '--role' => ['no_such_role'], '--generate' => true])->assertFailed();
        $this->assertNull(User::where('email', 'x@alquds.example')->first());
    }

    public function test_demo_users_seeder_creates_one_user_per_role_and_refuses_production(): void
    {
        $this->seed(DemoUsersSeeder::class);
        foreach (Roles::ALL as $role) {
            $this->assertTrue(User::where('email', "{$role}@alquds.test")->firstOrFail()->hasRole($role));
        }

        // Called directly: in production `db:seed` would first ask for confirmation.
        $this->app['env'] = 'production';
        $this->expectException(\RuntimeException::class);
        (new DemoUsersSeeder)->run();
    }

    // ---- approving a Google sign-in ----------------------------------------------

    public function test_the_pending_notice_links_to_the_edit_page(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->enableGoogle();

        $this->googleCallback()->assertRedirect(route('sso.pending'));

        $pending = User::where('email', 'new.hire@alquds.example')->firstOrFail();
        $this->assertSame(route('users.edit', $pending), ErpNotification::where('user_id', $manager->id)->latest('id')->value('url'));
    }

    public function test_an_approval_assigns_roles_and_then_google_signs_them_in(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->enableGoogle();
        $this->googleCallback();
        $pending = User::where('email', 'new.hire@alquds.example')->firstOrFail();

        $this->actingAs($manager)->put(route('users.update', $pending), [
            'name' => $pending->name, 'email' => $pending->email, 'status' => 'active', 'roles' => [Roles::STOREKEEPER],
        ])->assertRedirect(route('users.index'));

        $approved = $pending->fresh();
        $this->assertSame('active', $approved->status);
        $this->assertSame([Roles::STOREKEEPER], $approved->getRoleNames()->all());
        $this->assertNotNull($approved->approved_at);
        $this->assertSame($manager->id, $approved->approved_by);
        $this->assertSame(1, ErpNotification::where('user_id', $approved->id)->where('category', NotificationType::USER_APPROVED)->count());

        $this->post(route('logout'));
        $this->googleCallback()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($approved);
    }

    public function test_an_active_account_cannot_be_pushed_back_to_pending(): void
    {
        $user = $this->make(Roles::STOREKEEPER);

        $this->actingAs($this->make(Roles::MANAGER))->put(route('users.update', $user), [
            'name' => $user->name, 'email' => $user->email, 'status' => 'pending', 'roles' => [Roles::STOREKEEPER],
        ])->assertSessionHasErrors('status');
    }

    public function test_the_users_page_shows_who_is_waiting(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->enableGoogle();
        $this->googleCallback();

        $this->actingAs($manager)->get(route('users.index'))->assertOk()->assertSee('بانتظار تعيين دور');
        $this->actingAs($manager)->get(route('users.index', ['status' => 'pending']))->assertOk()
            ->assertSee('new.hire@alquds.example')
            ->assertSee('بانتظار الموافقة');
    }

    public function test_the_edit_page_explains_how_to_approve_and_lists_sign_in_addresses(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->enableGoogle();
        $this->googleCallback();
        $pending = User::where('email', 'new.hire@alquds.example')->firstOrFail();

        $this->actingAs($manager)->get(route('users.edit', $pending))->assertOk()
            ->assertSee('بانتظار الموافقة')
            ->assertSee('عناوين الدخول بحساب Google')
            ->assertSee('new.hire@alquds.example');
        $this->actingAs($manager)->get(route('users.show', $pending))->assertOk()->assertSee('بانتظار الموافقة');
    }

    // ---- two addresses, one person -----------------------------------------------

    public function test_a_second_address_is_linked_from_the_users_screen(): void
    {
        $this->enableGoogle();
        $manager = $this->make(Roles::MANAGER);
        $yosef = $this->make(Roles::STOREKEEPER);

        $this->actingAs($manager)->post(route('users.sso-identities.store', $yosef), ['email' => 'Yosef@Alquds.Example'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('user_sso_identities', ['user_id' => $yosef->id, 'email' => 'yosef@alquds.example', 'added_by' => $manager->id]);
    }

    public function test_a_second_address_must_be_on_the_allowed_domains_and_nobody_elses(): void
    {
        $this->enableGoogle();
        $manager = $this->make(Roles::MANAGER);
        $yosef = $this->make(Roles::STOREKEEPER);
        User::factory()->create(['email' => 'taken@alquds.example']);

        $this->actingAs($manager)->post(route('users.sso-identities.store', $yosef), ['email' => 'yosef@gmail.com'])->assertSessionHasErrors('sso_email');
        $this->actingAs($manager)->post(route('users.sso-identities.store', $yosef), ['email' => 'taken@alquds.example'])->assertSessionHasErrors('sso_email');

        $this->assertSame(0, UserSsoIdentity::where('user_id', $yosef->id)->count());
    }

    public function test_an_admin_who_cannot_manage_the_user_cannot_link_an_address_either(): void
    {
        $this->enableGoogle();
        $sysadmin = $this->make(Roles::SYSTEM_ADMIN);
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($sysadmin)->post(route('users.sso-identities.store', $manager), ['email' => 'm2@alquds.example'])->assertForbidden();
    }
}
