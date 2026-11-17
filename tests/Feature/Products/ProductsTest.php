<?php

namespace Tests\Feature\Products;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §3.2, §6: the product catalog, its photos, and supplier prices kept from those who may not see them. */
class ProductsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Blue ceramic cup', 'name_local' => '蓝色陶瓷杯', 'unit' => 'pcs', 'qty_per_carton' => '48',
            'carton_length_cm' => '60', 'carton_width_cm' => '40', 'carton_height_cm' => '35', 'cbm_per_carton' => '0.084',
            'kg_per_carton' => '12.5', 'sale_price' => '1.85', 'sale_currency' => 'USD', 'hs_code' => '6912.00', 'active' => '1',
        ], $overrides);
    }

    public function test_the_screen_is_forbidden_without_permission_and_read_only_with_view(): void
    {
        $staff = $this->make(Roles::STAFF);
        $qc = $this->make(Roles::WAREHOUSE_QC); // products: view
        $product = Product::factory()->create(['name' => 'Plastic basket']);

        $this->actingAs($staff)->get('/products')->assertForbidden();
        $this->actingAs($staff)->get("/products/{$product->id}")->assertForbidden();
        $this->actingAs($staff)->post('/products', $this->payload())->assertForbidden();

        $this->actingAs($qc)->get('/products')->assertOk()->assertSee('Plastic basket')->assertDontSee(route('products.create'));
        $this->actingAs($qc)->get("/products/{$product->id}")->assertOk();
        $this->actingAs($qc)->get("/products/{$product->id}/edit")->assertForbidden();
        $this->actingAs($qc)->put("/products/{$product->id}", $this->payload())->assertForbidden();
        $this->actingAs($qc)->post("/products/{$product->id}/deactivate")->assertForbidden();
    }

    public function test_a_product_is_created_with_a_photo_numbered_and_audited_and_the_photo_route_serves_it(): void
    {
        Storage::fake('local');
        $sales = $this->make(Roles::SALES);

        $this->actingAs($sales)->post('/products', $this->payload(['photo' => UploadedFile::fake()->image('p.jpg', 400, 400)]))
            ->assertRedirect();

        $product = Product::firstOrFail();
        $this->assertSame('P-0001', $product->code);
        $this->assertSame('0.0840', $product->cbm_per_carton);
        $this->assertSame($sales->id, $product->created_by);
        $this->assertNotNull($product->photo);
        Storage::disk('local')->assertExists($product->photo);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Product::class, 'model_id' => $product->id, 'action' => 'created']);

        $this->actingAs($sales)->get("/products/{$product->id}/photo")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        // Any signed-in user: the PI print and the follow-up board show it too.
        $this->actingAs($this->make(Roles::STAFF))->get(route('products.photo', $product))->assertOk();
        $this->actingAs($sales)->get('/products')->assertSee(route('products.photo', $product));

        auth()->logout();
        $this->get(route('products.photo', $product))->assertRedirect('/login');
    }

    public function test_the_photo_route_is_a_404_without_a_photo_and_a_bad_upload_is_refused(): void
    {
        Storage::fake('local');
        $manager = $this->make(Roles::MANAGER);
        $product = Product::factory()->create();

        $this->actingAs($manager)->get(route('products.photo', $product))->assertNotFound();
        $this->actingAs($manager)->post('/products', $this->payload(['photo' => UploadedFile::fake()->create('x.svg', 10, 'image/svg+xml')]))
            ->assertSessionHasErrors('photo');
        $this->assertSame(1, Product::count());
    }

    public function test_a_new_photo_replaces_the_old_one_and_can_be_removed(): void
    {
        Storage::fake('local');
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager)->post('/products', $this->payload(['photo' => UploadedFile::fake()->image('a.jpg')]));
        $product = Product::firstOrFail();
        $first = $product->photo;

        $this->actingAs($manager)->put("/products/{$product->id}", $this->payload(['photo' => UploadedFile::fake()->image('b.png')]))->assertRedirect();
        $product->refresh();
        $this->assertNotSame($first, $product->photo);
        Storage::disk('local')->assertMissing($first);

        $this->actingAs($manager)->delete(route('products.photo.destroy', $product))->assertRedirect();
        $this->assertNull($product->fresh()->photo);
    }

    public function test_cbm_is_worked_out_from_the_carton_when_left_empty(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/products', $this->payload(['cbm_per_carton' => '', 'carton_length_cm' => '58.5', 'carton_width_cm' => '39', 'carton_height_cm' => '41']))
            ->assertSessionHasNoErrors();

        // 58.5 × 39 × 41 = 93,541.5 cm³ = 0.0935415 m³ → 0.0935
        $this->assertSame('0.0935', Product::first()->cbm_per_carton);
    }

    public function test_an_update_is_audited_and_the_code_never_changes(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager)->post('/products', $this->payload());
        $product = Product::firstOrFail();

        $this->actingAs($manager)->put("/products/{$product->id}", $this->payload(['name' => 'Navy ceramic cup', 'code' => 'X-1']))->assertRedirect(route('products.show', $product));

        $product->refresh();
        $this->assertSame('P-0001', $product->code);
        $this->assertSame('Navy ceramic cup', $product->name);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Product::class, 'model_id' => $product->id, 'action' => 'updated']);
    }

    public function test_the_index_searches_and_filters(): void
    {
        $merch = $this->make(Roles::MERCHANDISER);
        $supplier = Party::factory()->supplier()->create();
        Product::factory()->create(['name' => 'Red umbrella', 'supplier_id' => $supplier->id]);
        Product::factory()->create(['name' => 'Green bucket', 'active' => false]);

        $this->actingAs($merch)->get('/products?search=umbrel')->assertSee('Red umbrella')->assertDontSee('Green bucket');
        $this->actingAs($merch)->get('/products')->assertSee('Red umbrella')->assertDontSee('Green bucket');
        $this->actingAs($merch)->get('/products?active=0')->assertSee('Green bucket')->assertDontSee('Red umbrella');
        $this->actingAs($merch)->get('/products?active=all&supplier='.$supplier->id)->assertSee('Red umbrella')->assertDontSee('Green bucket');
    }

    public function test_deactivating_and_reactivating_a_product(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $product = Product::factory()->create();

        $this->actingAs($manager)->post(route('products.deactivate', $product))->assertRedirect(route('products.show', $product));
        $this->assertFalse($product->fresh()->active);
        $this->actingAs($manager)->post(route('products.reactivate', $product))->assertRedirect();
        $this->assertTrue($product->fresh()->active);
        $this->assertSame(['deactivated', 'reactivated'], AuditLog::where('model_type', Product::class)->orderBy('id')->pluck('action')->all());
        $this->assertFalse(app('router')->has('products.destroy'));
    }

    public function test_supplier_and_supplier_price_are_hidden_from_a_user_who_may_not_see_supplier_prices(): void
    {
        $sales = $this->make(Roles::SALES);
        $merch = $this->make(Roles::MERCHANDISER);
        $supplier = Party::factory()->supplier()->create(['name' => 'Hidden Ceramics Factory']);
        $product = Product::factory()->create(['name' => 'Cup', 'supplier_id' => $supplier->id, 'supplier_price' => '7.6543', 'supplier_currency' => 'CNY']);

        $this->actingAs($sales)->get('/products')->assertOk()->assertDontSee('Hidden Ceramics Factory')->assertDontSee('7.6543');
        $this->actingAs($sales)->get("/products/{$product->id}")->assertOk()->assertDontSee('Hidden Ceramics Factory')->assertDontSee('7.6543');
        $this->actingAs($sales)->get("/products/{$product->id}/edit")->assertOk()->assertDontSee('7.6543')->assertDontSee('name="supplier_price"', false);

        // Sales may edit the product, but not its supplier side, even when it is sent.
        $this->actingAs($sales)->put("/products/{$product->id}", $this->payload(['supplier_price' => '0.01', 'supplier_id' => '']))->assertRedirect();
        $product->refresh();
        $this->assertSame('7.6543', $product->supplier_price);
        $this->assertSame($supplier->id, $product->supplier_id);

        $this->actingAs($merch)->get('/products')->assertSee('Hidden Ceramics Factory')->assertSee('7.6543');
        $this->actingAs($merch)->get("/products/{$product->id}")->assertSee('7.6543');
    }

    public function test_sales_see_supplier_prices_once_the_option_is_on(): void
    {
        $settings = BusinessSetting::current();
        $settings->sales_see_supplier_prices = true;
        $settings->save();
        $sales = $this->make(Roles::SALES);
        Product::factory()->create(['supplier_price' => '3.2100', 'supplier_currency' => 'CNY']);

        $this->actingAs($sales)->get('/products')->assertSee('3.21');
    }

    public function test_the_supplier_must_be_a_supplier(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $client = Party::factory()->client()->create();

        $this->actingAs($manager)->post('/products', $this->payload(['supplier_id' => $client->id, 'supplier_price' => '5', 'supplier_currency' => 'CNY']))
            ->assertSessionHasErrors('supplier_id');
        $this->actingAs($manager)->post('/products', $this->payload(['qty_per_carton' => '0']))->assertSessionHasErrors('qty_per_carton');
        $this->assertSame(0, Product::count());
    }
}
