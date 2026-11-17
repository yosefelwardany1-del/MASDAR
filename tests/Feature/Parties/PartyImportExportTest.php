<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Security\Roles;
use App\Support\IO\Csv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Import & export of clients, suppliers and products: written through PartyService and
 * ProductService (never the generic upsert), keyed by code, with the screens' data rules.
 */
class PartyImportExportTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function csv(array $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rows.csv', Csv::write($rows));
    }

    public function test_clients_are_imported_through_the_writer_numbered_and_updated_by_code(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $existing = Party::factory()->client()->create(['code' => 'C-0500', 'name' => 'Old name', 'city' => 'Riyadh']);

        $this->actingAs($manager)->post(route('io.import', 'clients'), ['file' => $this->csv([
            ['code', 'name', 'country', 'currency', 'stage', 'deposit_percent', 'account_manager_email'],
            ['', 'New Client Co', 'Saudi Arabia', 'USD', 'lead', '40', $manager->email],
            ['C-0500', 'New name', 'SA', '', '', '', ''],
            ['C-9999', 'Ghost', '', '', '', '', ''],
            ['', 'Bad list', 'Atlantis', '', '', '', ''],
        ])])->assertRedirect()->assertSessionHas('import_result', fn ($r) => $r['created'] === 1 && $r['updated'] === 1 && $r['failed'] === 2);

        $new = Party::where('name', 'New Client Co')->firstOrFail();
        $this->assertSame('C-0001', $new->code);
        $this->assertTrue($new->is_client);
        $this->assertSame('SA', $new->country);
        $this->assertSame('40.00', $new->deposit_percent);
        $this->assertSame($manager->id, $new->account_manager_id);
        $this->assertSame($manager->id, $new->created_by);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Party::class, 'model_id' => $new->id, 'action' => 'created']);

        $existing->refresh();
        $this->assertSame('New name', $existing->name);
        $this->assertSame('Riyadh', $existing->city, 'a column the file leaves out is left as it is');
        $this->assertSame(0, Party::whereIn('name', ['Ghost', 'Bad list'])->count());
    }

    public function test_a_preview_writes_nothing(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post(route('io.preview', 'suppliers'), ['file' => $this->csv([
            ['code', 'name'], ['', 'Preview Factory'],
        ])])->assertOk()->assertJson(['created' => 1, 'failed' => 0]);

        $this->assertSame(0, Party::count());
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_import_is_gated_by_the_resources_own_screen(): void
    {
        $sales = $this->make(Roles::SALES);
        $sales->givePermissionTo(['import_export.view', 'import_export.edit']);

        $this->actingAs($sales)->post(route('io.import', 'suppliers'), ['file' => $this->csv([['name'], ['Sneaky']])])->assertForbidden();
        $this->actingAs($sales)->get(route('io.export', 'suppliers'))->assertForbidden();
        $this->assertSame(0, Party::count());
    }

    public function test_a_sales_user_exports_and_updates_only_their_own_clients(): void
    {
        $sales = $this->make(Roles::SALES);
        $sales->givePermissionTo(['import_export.view', 'import_export.edit']);
        $other = $this->make(Roles::SALES);
        Party::factory()->client()->create(['code' => 'C-0100', 'name' => 'Mine Ltd', 'account_manager_id' => $sales->id]);
        Party::factory()->client()->create(['code' => 'C-0200', 'name' => 'Theirs Ltd', 'account_manager_id' => $other->id]);

        $body = $this->actingAs($sales)->get(route('io.export', 'clients'))->assertOk()->getContent();
        $this->assertStringContainsString('Mine Ltd', $body);
        $this->assertStringNotContainsString('Theirs Ltd', $body);

        $this->actingAs($sales)->post(route('io.import', 'clients'), ['file' => $this->csv([
            ['code', 'name', 'account_manager_email'], ['C-0200', 'Taken over', ''], ['', 'Brand new', $other->email],
        ])])->assertSessionHas('import_result', fn ($r) => $r['created'] === 1 && $r['failed'] === 1);

        $this->assertSame('Theirs Ltd', Party::where('code', 'C-0200')->value('name'));
        $this->assertSame($sales->id, Party::where('name', 'Brand new')->value('account_manager_id'));
    }

    public function test_supplier_bank_details_and_product_supplier_prices_follow_the_visibility_rule(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $sales = $this->make(Roles::SALES);
        $sales->givePermissionTo(['import_export.view', 'import_export.edit']);
        $supplier = Party::factory()->supplier()->create(['code' => 'S-0042', 'bank_details' => 'BOC 1234-SECRET']);
        $product = Product::factory()->create(['code' => 'P-0042', 'name' => 'Cup', 'supplier_id' => $supplier->id, 'supplier_price' => '6.6600', 'supplier_currency' => 'CNY']);

        $this->assertStringContainsString('1234-SECRET', $this->actingAs($manager)->get(route('io.export', 'suppliers'))->getContent());
        $this->assertStringContainsString('6.6600', $this->actingAs($manager)->get(route('io.export', 'products'))->getContent());

        $body = $this->actingAs($sales)->get(route('io.export', 'products'))->assertOk()->getContent();
        $this->assertStringNotContainsString('6.6600', $body);
        $this->assertStringNotContainsString('S-0042', $body);

        $this->actingAs($sales)->post(route('io.import', 'products'), ['file' => $this->csv([
            ['code', 'name', 'supplier_code', 'supplier_price', 'sale_price', 'sale_currency'],
            ['P-0042', 'Cup v2', '', '0.01', '2.10', 'USD'],
        ])])->assertSessionHas('import_result', fn ($r) => $r['updated'] === 1 && $r['failed'] === 0);

        $product->refresh();
        $this->assertSame('Cup v2', $product->name);
        $this->assertSame('2.1000', $product->sale_price);
        $this->assertSame('6.6600', $product->supplier_price);
        $this->assertSame($supplier->id, $product->supplier_id);
    }

    public function test_products_are_imported_numbered_with_their_supplier_and_cbm(): void
    {
        $merch = $this->make(Roles::MERCHANDISER);
        $merch->givePermissionTo(['import_export.view', 'import_export.edit']);
        Party::factory()->supplier()->create(['code' => 'S-0007']);

        $this->actingAs($merch)->post(route('io.import', 'products'), ['file' => $this->csv([
            ['code', 'name', 'unit', 'qty_per_carton', 'carton_length_cm', 'carton_width_cm', 'carton_height_cm', 'supplier_code', 'supplier_price', 'supplier_currency'],
            ['', 'Glass vase', 'Pieces', '12', '50', '40', '30', 'S-0007', '9.5', 'CNY'],
            ['', 'No such supplier', 'pcs', '12', '', '', '', 'S-9999', '', ''],
        ])])->assertSessionHas('import_result', fn ($r) => $r['created'] === 1 && $r['failed'] === 1);

        $vase = Product::where('name', 'Glass vase')->firstOrFail();
        $this->assertSame('P-0001', $vase->code);
        $this->assertSame('pcs', $vase->unit);
        $this->assertSame('0.0600', $vase->cbm_per_carton);
        $this->assertSame('9.5000', $vase->supplier_price);
        $this->assertNotNull($vase->supplier_id);
    }
}
