<?php

namespace Tests\Feature\Platform;

use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\User;
use App\Security\Roles;
use App\Security\Screens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\Fixtures\TestDoc;
use Tests\TestCase;

/**
 * One attachment pipeline for every attachable: private disk, served only through the
 * controller, gated by the parent's screen ({module}.view to download, {module}.edit to
 * upload or delete), fail-closed, size and type limited, audited.
 */
class AttachmentSystemTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private TestDoc $doc;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        TestDoc::createTable();
        config(['attachments.attachables' => ['test-doc' => TestDoc::class]]);
        $this->doc = TestDoc::create(['title' => 'A document']);
    }

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function upload(array $files)
    {
        return $this->post(route('attachments.store', ['type' => 'test-doc', 'id' => $this->doc->id]), ['files' => $files]);
    }

    public function test_upload_download_and_delete(): void
    {
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN));

        $this->upload([
            UploadedFile::fake()->create('receipt.pdf', 200, 'application/pdf'),
            UploadedFile::fake()->image('proof.png'),
        ])->assertRedirect();

        $this->assertSame(2, $this->doc->attachments()->count());
        $att = $this->doc->attachments()->first();
        $this->assertSame('local', $att->disk);
        Storage::disk('local')->assertExists($att->path);

        $this->get(route('attachments.download', $att))->assertOk();

        $this->delete(route('attachments.destroy', $att))->assertRedirect();
        $this->assertFalse(Attachment::whereKey($att->id)->exists());
        Storage::disk('local')->assertMissing($att->path);

        $this->assertSame(2, AuditLog::where('model_type', Attachment::class)->where('action', 'created')->count());
        $this->assertSame(1, AuditLog::where('model_type', Attachment::class)->where('action', 'deleted')->count());
    }

    public function test_disallowed_type_and_oversized_file_are_rejected(): void
    {
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN));

        $this->upload([UploadedFile::fake()->create('malware.exe', 100)])->assertSessionHasErrors('files.0');
        $this->upload([UploadedFile::fake()->create('huge.pdf', config('attachments.max_size_kb') + 1024, 'application/pdf')])->assertSessionHasErrors('files.0');

        $this->assertSame(0, $this->doc->attachments()->count());
    }

    public function test_access_is_fail_closed_without_the_parents_screen(): void
    {
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN));
        $this->upload([UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $att = $this->doc->attachments()->first();

        $this->actingAs($this->make(Roles::STOREKEEPER));
        $this->upload([UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->get(route('attachments.download', $att))->assertForbidden();
        $this->delete(route('attachments.destroy', $att))->assertForbidden();
    }

    public function test_view_level_downloads_but_cannot_change(): void
    {
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN));
        $this->upload([UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]);
        $att = $this->doc->attachments()->first();

        Role::findByName(Roles::ACCOUNTANT)->givePermissionTo(Screens::grantsFor('settings', 'view'));
        $this->actingAs($this->make(Roles::ACCOUNTANT));

        $this->get(route('attachments.download', $att))->assertOk();
        $this->upload([UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')])->assertForbidden();
        $this->delete(route('attachments.destroy', $att))->assertForbidden();
    }

    public function test_unknown_type_is_not_found(): void
    {
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN));

        $this->post(route('attachments.store', ['type' => 'nope', 'id' => 1]), ['files' => []])->assertNotFound();
    }
}
