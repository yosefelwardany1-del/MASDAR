<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Security\Roles;
use App\Services\Products\ProductService;
use App\Support\Audit\AuditTrail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B25: the audit log names its actions, filters by every action it holds, and records only real changes. */
class ReadableAuditLogTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_only_real_changes_are_recorded_and_the_placeholder_is_gone(): void
    {
        $product = Product::factory()->create(['unit' => 'box', 'active' => true, 'supplier_price' => '2.5000']);
        $before = AuditLog::count();

        // Nothing changes but the types: not logged at all.
        app(ProductService::class)->update($product, ['unit' => 'box', 'active' => true, 'supplier_price' => '2.5'], null);
        $this->assertSame($before, AuditLog::count());

        // One real change: only that field, and no "unchanged" price.
        app(ProductService::class)->update($product->fresh(), ['unit' => 'ctn', 'active' => true, 'supplier_price' => '2.50'], null);
        $log = AuditLog::query()->latest('id')->first();
        $this->assertSame(['unit' => 'box'], $log->old_values);
        $this->assertSame(['unit' => 'ctn'], $log->new_values);

        // 2 → "2", true → 1, "1.5000" → "1.5" are not changes.
        $row = AuditTrail::record('step_done', $product, ['supplier_id' => 2, 'active' => true, 'note' => 'x'], ['supplier_id' => '2', 'active' => 1, 'note' => 'y']);
        $this->assertSame([['note' => 'x'], ['note' => 'y']], [$row->old_values, $row->new_values]);
    }

    public function test_actions_read_as_words_in_every_language_and_the_filter_lists_them_all(): void
    {
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $product = Product::factory()->create();
        AuditTrail::record('posted', $product, [], ['number' => 'JE-1', 'memo' => null]);
        AuditTrail::record('lines_taken_back', $product, [], ['lines' => 1]);

        $page = $this->actingAs($manager)->get(route('audit-logs.index'))->assertOk();
        $page->assertSee('Posted')->assertSee('Lines taken back')->assertDontSee('audit.posted')->assertDontSee('>null<', false);
        $page->assertSee('<option value="posted"', false)->assertSee('<option value="lines_taken_back"', false);
        $this->assertStringContainsString('—', $page->getContent());

        $manager->forceFill(['locale' => 'zh_CN'])->save();
        $this->actingAs($manager)->get(route('audit-logs.index'))->assertSee('已过账')->assertSee('已收回行');
        $manager->forceFill(['locale' => 'ar'])->save();
        $this->actingAs($manager)->get(route('audit-logs.index'))->assertSee('استُعيدت أسطر');
    }
}
