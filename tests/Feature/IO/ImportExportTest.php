<?php

namespace Tests\Feature\IO;

use App\Models\ImportRun;
use App\Models\User;
use App\Security\Roles;
use App\Security\Screens;
use App\Support\IO\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\Concerns\SeedsRoles;
use Tests\Fixtures\TestItem;
use Tests\Fixtures\TestItemSchema;
use Tests\TestCase;

/**
 * The import/export platform — template, export, import (create + update, row-level
 * errors), preview (no writes) and permission gating — through a test-only resource,
 * since no business resource exists yet. Every resource is reached through TWO gates:
 * the import_export screen, and the resource's own screen.
 */
class ImportExportTest extends TestCase
{
    use RefreshDatabase, SeedsRoles;

    private const HEADER = ['sku', 'name', 'pages'];

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        TestItem::createTable();
        config(['io.resources' => [TestItemSchema::class]]);
        $this->manager = User::factory()->create()->assignRole(Roles::MANAGER);
    }

    private function upload(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('items.csv', Csv::write(array_merge([self::HEADER], $rows)));
    }

    /** A user whose only role grants exactly these screen levels. */
    private function userWith(array $levels): User
    {
        $role = Role::create(['name' => 'io-'.uniqid()]);
        foreach ($levels as $screen => $level) {
            $role->givePermissionTo(Screens::grantsFor($screen, $level));
        }

        return User::factory()->create()->assignRole($role);
    }

    public function test_template_download_matches_schema(): void
    {
        $body = $this->actingAs($this->manager)->get(route('io.template', 'test-items'))->assertOk()->getContent();

        $this->assertStringContainsString('sku,name,pages', $body);
        $this->assertStringContainsString('#', $body, 'hint row marker');
    }

    public function test_export_lists_every_row_and_neutralises_formulas(): void
    {
        TestItem::create(['sku' => 'BK-1', 'name' => 'First', 'pages' => 100]);
        TestItem::create(['sku' => 'BK-2', 'name' => '=HYPERLINK("x")', 'pages' => null]);

        $body = $this->actingAs($this->manager)->get(route('io.export', 'test-items'))->assertOk()->getContent();

        $this->assertStringContainsString('BK-1,First,100', $body);
        $this->assertStringContainsString("'=HYPERLINK", $body, 'a cell a spreadsheet would execute is defused');
    }

    public function test_import_creates_then_updates_and_records_the_run(): void
    {
        $this->actingAs($this->manager)
            ->post(route('io.import', 'test-items'), ['file' => $this->upload([['BK-100', 'Jane', '120']])])
            ->assertRedirect();

        $item = TestItem::where('sku', 'BK-100')->firstOrFail();
        $this->assertSame('Jane', $item->name);

        $this->post(route('io.import', 'test-items'), ['file' => $this->upload([['BK-100', 'Janet', '120']])])
            ->assertRedirect();

        $this->assertSame('Janet', $item->fresh()->name);
        $this->assertSame(1, TestItem::where('sku', 'BK-100')->count(), 'an update must not duplicate');

        $runs = ImportRun::where('resource', 'test-items')->orderBy('id')->get();
        $this->assertSame([1, 0], [$runs[0]->created_count, $runs[0]->updated_count]);
        $this->assertSame([0, 1], [$runs[1]->created_count, $runs[1]->updated_count]);
        $this->assertSame($this->manager->id, $runs[0]->user_id);
    }

    public function test_import_reports_row_level_errors_and_imports_valid_rows(): void
    {
        $data = $this->actingAs($this->manager)->postJson(route('io.import', 'test-items'), ['file' => $this->upload([
            ['OK-1', 'Good', '10'],             // row 2: valid
            ['BAD-1', '', '10'],                // row 3: missing name
            ['BAD-2', 'unresolvable', ''],      // row 4: a reference that does not resolve
            ['OK-1', 'Again', ''],              // row 5: in-file duplicate key
        ])])->assertOk()->json();

        $this->assertSame(1, $data['created']);
        $this->assertSame(3, $data['failed']);
        $this->assertEqualsCanonicalizing([3, 4, 5], collect($data['errors'])->pluck('row')->all());
        $this->assertTrue(TestItem::where('sku', 'OK-1')->exists());
        $this->assertFalse(TestItem::whereIn('sku', ['BAD-1', 'BAD-2'])->exists());
    }

    public function test_preview_does_not_write(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('io.preview', 'test-items'), ['file' => $this->upload([['PREV-1', 'Preview', '']])])
            ->assertOk()->assertJson(['created' => 1, 'failed' => 0]);

        $this->assertFalse(TestItem::where('sku', 'PREV-1')->exists(), 'a preview must not persist');
        $this->assertSame(0, ImportRun::count(), 'a preview leaves no history row');
    }

    public function test_the_import_export_screen_is_required(): void
    {
        // Full rights on the resource's own screen, none on import/export.
        $user = $this->userWith(['settings' => 'edit']);

        $this->actingAs($user)->get(route('import-export.index'))->assertForbidden();
        $this->actingAs($user)->get(route('io.template', 'test-items'))->assertForbidden();
        $this->actingAs($user)->get(route('io.export', 'test-items'))->assertForbidden();
        $this->actingAs($user)->post(route('io.import', 'test-items'), ['file' => $this->upload([['X-1', 'X', '']])])->assertForbidden();
    }

    public function test_the_resource_screen_is_required_too(): void
    {
        // Full rights on import/export, only view on the resource: may read, may not write.
        $user = $this->userWith(['import_export' => 'edit', 'settings' => 'view']);

        $this->actingAs($user)->get(route('io.template', 'test-items'))->assertOk();
        $this->actingAs($user)->get(route('io.export', 'test-items'))->assertOk();
        $this->actingAs($user)->post(route('io.preview', 'test-items'), ['file' => $this->upload([['X-1', 'X', '']])])->assertForbidden();
        $this->actingAs($user)->post(route('io.import', 'test-items'), ['file' => $this->upload([['X-1', 'X', '']])])->assertForbidden();
        $this->assertSame(0, TestItem::count());
    }

    public function test_import_export_view_alone_cannot_import(): void
    {
        $user = $this->userWith(['import_export' => 'view', 'settings' => 'edit']);

        $this->actingAs($user)->get(route('io.export', 'test-items'))->assertOk();
        $this->actingAs($user)->post(route('io.import', 'test-items'), ['file' => $this->upload([['X-1', 'X', '']])])->assertForbidden();
    }

    public function test_an_unknown_resource_is_404(): void
    {
        $this->actingAs($this->manager)->get(route('io.template', 'nope'))->assertNotFound();
    }

    public function test_the_center_lists_permitted_resources_and_only_my_own_runs(): void
    {
        $this->actingAs($this->manager)->post(route('io.import', 'test-items'), ['file' => $this->upload([['MINE-1', 'Mine', '']])]);
        $colleague = User::factory()->create(['name' => 'Colleague'])->assignRole(Roles::MANAGER);
        $this->actingAs($colleague)->post(route('io.import', 'test-items'), ['file' => $this->upload([['THEIRS-1', 'Theirs', '']])]);

        $page = $this->actingAs($this->manager)->get(route('import-export.index'))->assertOk();
        $page->assertSee('Test items');
        $page->assertSee(route('io.import', 'test-items'), false);
        $page->assertSee('items.csv');
        $this->assertSame(1, substr_count($page->getContent(), 'items.csv'), 'a colleague\'s upload is not listed');
    }

    public function test_the_center_speaks_arabic(): void
    {
        $this->actingAs($this->manager)->get(route('import-export.index'))->assertOk()
            ->assertSee('الاستيراد والتصدير')
            ->assertSee('قالب')
            ->assertSee('عمليات الاستيراد الأخيرة');
    }

    public function test_the_center_hides_a_resource_the_user_cannot_see(): void
    {
        $user = $this->userWith(['import_export' => 'edit']);

        $this->actingAs($user)->get(route('import-export.index'))->assertOk()->assertDontSee('Test items');
    }
}
