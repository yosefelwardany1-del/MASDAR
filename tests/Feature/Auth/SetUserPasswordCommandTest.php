<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetUserPasswordCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'name' => 'Admin', 'email' => 'Admin@Acme.Example',
            'password' => 'old-password-1234', 'status' => 'active', 'remember_token' => 'old-token',
        ]);
    }

    public function test_it_sets_the_password_from_a_hidden_prompt_and_rotates_remember_me(): void
    {
        $user = $this->user();

        $this->artisan('user:password', ['email' => 'admin@acme.example'])
            ->expectsQuestion('New password (hidden)', 'correct horse battery staple')
            ->expectsQuestion('Once more', 'correct horse battery staple')
            ->assertSuccessful();

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('correct horse battery staple', $fresh->password));
        $this->assertNotSame('old-token', $fresh->remember_token, 'other browsers must be signed out');
    }

    public function test_a_mismatch_changes_nothing(): void
    {
        $user = $this->user();

        $this->artisan('user:password', ['email' => 'admin@acme.example'])
            ->expectsQuestion('New password (hidden)', 'one-thing-entirely')
            ->expectsQuestion('Once more', 'another-thing-entirely')
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password-1234', $user->fresh()->password));
    }

    public function test_a_short_password_is_refused(): void
    {
        $user = $this->user();

        $this->artisan('user:password', ['email' => 'admin@acme.example'])
            ->expectsQuestion('New password (hidden)', 'short')
            ->expectsQuestion('Once more', 'short')
            ->assertFailed();

        $this->assertTrue(Hash::check('old-password-1234', $user->fresh()->password));
    }

    public function test_generate_prints_a_password_that_then_works(): void
    {
        $user = $this->user();

        $this->artisan('user:password', ['email' => 'admin@acme.example', '--generate' => true])->assertSuccessful();

        $this->assertFalse(Hash::check('old-password-1234', $user->fresh()->password));
    }

    public function test_an_unknown_address_fails_cleanly(): void
    {
        $this->artisan('user:password', ['email' => 'nobody@acme.example'])->assertFailed();
    }
}
