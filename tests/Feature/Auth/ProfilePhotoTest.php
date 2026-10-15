<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A profile photo is the signed-in user's own setting: there is no path to change
 * another login's picture. Files land on the private disk and are served by a route
 * that requires sign-in, never by a public URL.
 */
class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('me.png', 200, 200);
    }

    public function test_a_user_uploads_and_removes_their_own_photo(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => $this->image()])->assertRedirect();

        $user->refresh();
        $this->assertNotNull($user->avatar);
        $this->assertSame('local', $user->avatar_disk);
        Storage::disk('local')->assertExists($user->avatar);

        $this->actingAs($user)->get(route('profile.photo', $user))->assertOk();
        $this->assertSame(route('profile.photo', $user), $user->avatarUrl());

        $path = $user->avatar;
        $this->actingAs($user)->delete(route('profile.photo.destroy'))->assertRedirect();

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_uploading_a_photo_never_touches_another_login(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($me)->post(route('profile.photo.store'), ['photo' => $this->image()]);

        $this->assertNotNull($me->fresh()->avatar);
        $this->assertNull($other->fresh()->avatar);
    }

    public function test_an_svg_is_refused(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.photo.store'), ['photo' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml')])
            ->assertSessionHasErrors('photo');

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_a_guest_cannot_read_a_users_photo(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('profile.photo.store'), ['photo' => $this->image()]);

        auth()->logout();
        $this->get(route('profile.photo', $user))->assertRedirect(route('login'));
    }

    public function test_the_profile_page_updates_my_name(): void
    {
        $user = User::factory()->create(['name' => 'Old']);

        $this->actingAs($user)->get(route('profile'))->assertOk()->assertSee('ملفي الشخصي');
        $this->actingAs($user)->put(route('profile.update'), ['name' => 'جديد', 'email' => $user->email])->assertRedirect();

        $this->assertSame('جديد', $user->fresh()->name);
    }
}
