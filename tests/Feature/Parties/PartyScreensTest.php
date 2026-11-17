<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\ProformaInvoice;
use App\Models\Shipment;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §3.1, §5.1, §6: one party registry on three screens, each granted on its own; codes
 * numbered from the first kind; sales users see only their own clients; supplier bank details
 * only for those who may see supplier prices.
 */
class PartyScreensTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private const SCREENS = ['clients', 'suppliers', 'shipping-companies'];

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Gulf Trading LLC', 'name_local' => 'الخليج للتجارة', 'country' => 'AE', 'city' => 'Dubai',
            'currency' => 'USD', 'active' => '1', 'kinds_sent' => '1',
        ], $overrides);
    }

    // ---- permissions ---------------------------------------------------------------

    public function test_each_party_screen_is_forbidden_without_its_permission(): void
    {
        $staff = $this->make(Roles::STAFF);
        $party = Party::factory()->create(['is_client' => true, 'is_supplier' => true, 'is_shipping' => true]);

        foreach (self::SCREENS as $uri) {
            $this->actingAs($staff)->get("/{$uri}")->assertForbidden();
            $this->actingAs($staff)->get("/{$uri}/create")->assertForbidden();
            $this->actingAs($staff)->get("/{$uri}/{$party->id}")->assertForbidden();
            $this->actingAs($staff)->post("/{$uri}", $this->payload())->assertForbidden();
            $this->actingAs($staff)->put("/{$uri}/{$party->id}", $this->payload())->assertForbidden();
            $this->actingAs($staff)->post("/{$uri}/{$party->id}/deactivate")->assertForbidden();
        }
        $this->assertSame(1, Party::count());

        auth()->logout();
        $this->get('/clients')->assertRedirect('/login');
    }

    public function test_sales_can_edit_clients_but_not_suppliers_or_shipping_companies(): void
    {
        $sales = $this->make(Roles::SALES);
        $supplier = Party::factory()->supplier()->create();

        $this->actingAs($sales)->get('/clients')->assertOk();
        $this->actingAs($sales)->get('/clients/create')->assertOk();
        $this->actingAs($sales)->post('/clients', $this->payload())->assertRedirect();
        $this->assertDatabaseHas('parties', ['name' => 'Gulf Trading LLC', 'is_client' => true]);

        $this->actingAs($sales)->get('/suppliers')->assertForbidden();
        $this->actingAs($sales)->get("/suppliers/{$supplier->id}")->assertForbidden();
        $this->actingAs($sales)->post('/suppliers', $this->payload(['name' => 'Factory']))->assertForbidden();
        $this->actingAs($sales)->get('/shipping-companies')->assertForbidden();
        $this->assertDatabaseMissing('parties', ['name' => 'Factory']);
    }

    public function test_a_viewer_reads_a_screen_but_cannot_write_it(): void
    {
        $accountant = $this->make(Roles::ACCOUNTANT); // suppliers: view
        $supplier = Party::factory()->supplier()->create(['name' => 'Yiwu Toys Factory']);

        $this->actingAs($accountant)->get('/suppliers')->assertOk()->assertSee('Yiwu Toys Factory')->assertDontSee(route('suppliers.create'));
        $this->actingAs($accountant)->get("/suppliers/{$supplier->id}")->assertOk();
        $this->actingAs($accountant)->get("/suppliers/{$supplier->id}/edit")->assertForbidden();
        $this->actingAs($accountant)->put("/suppliers/{$supplier->id}", $this->payload())->assertForbidden();
    }

    public function test_every_index_show_and_form_renders_for_a_manager(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $parties = [
            'clients' => Party::factory()->client()->create(['name' => 'Client One', 'stage' => 'lead']),
            'suppliers' => Party::factory()->supplier()->create(['name' => 'Supplier One', 'products_note' => 'Plastic toys']),
            'shipping-companies' => Party::factory()->shippingCompany()->create(['name' => 'Forwarder One']),
        ];

        foreach ($parties as $uri => $party) {
            $this->actingAs($manager)->get("/{$uri}")->assertOk()->assertSee($party->name);
            $this->actingAs($manager)->get("/{$uri}/create")->assertOk();
            $this->actingAs($manager)->get("/{$uri}/{$party->id}")->assertOk()->assertSee($party->code);
            $this->actingAs($manager)->get("/{$uri}/{$party->id}/edit")->assertOk();
        }
    }

    public function test_a_screen_shows_only_parties_of_its_kind(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $client = Party::factory()->client()->create(['name' => 'Only A Client']);
        $supplier = Party::factory()->supplier()->create(['name' => 'Only A Supplier']);

        $this->actingAs($manager)->get('/clients')->assertSee('Only A Client')->assertDontSee('Only A Supplier');
        $this->actingAs($manager)->get("/clients/{$supplier->id}")->assertNotFound();
        $this->actingAs($manager)->get("/suppliers/{$client->id}")->assertNotFound();
        $this->actingAs($manager)->get('/clients/abc')->assertNotFound();
    }

    // ---- numbering and audit -------------------------------------------------------

    public function test_creating_each_kind_numbers_it_from_its_series_and_audits_it(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/clients', $this->payload(['name' => 'First client']))->assertRedirect();
        $this->actingAs($manager)->post('/clients', $this->payload(['name' => 'Second client']))->assertRedirect();
        $this->actingAs($manager)->post('/suppliers', $this->payload(['name' => 'First supplier', 'currency' => 'CNY']))->assertRedirect();
        $this->actingAs($manager)->post('/shipping-companies', $this->payload(['name' => 'First forwarder', 'shipping_kind' => 'forwarder']))->assertRedirect();

        $this->assertSame('C-0001', Party::where('name', 'First client')->value('code'));
        $this->assertSame('C-0002', Party::where('name', 'Second client')->value('code'));
        $this->assertSame('S-0001', Party::where('name', 'First supplier')->value('code'));
        $forwarder = Party::where('name', 'First forwarder')->first();
        $this->assertSame('SC-0001', $forwarder->code);
        $this->assertTrue($forwarder->is_shipping);
        $this->assertSame('forwarder', $forwarder->shipping_kind);
        $this->assertSame($manager->id, $forwarder->created_by);

        foreach (Party::all() as $party) {
            $audit = AuditLog::where('model_type', Party::class)->where('model_id', $party->id)->where('action', 'created')->first();
            $this->assertNotNull($audit, "{$party->code} is audited");
            $this->assertSame($manager->id, $audit->user_id);
            $this->assertSame($party->code, $audit->new_values['code']);
        }
    }

    public function test_a_party_of_several_kinds_is_numbered_from_its_first_kind(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/suppliers', $this->payload(['name' => 'Buys and sells', 'kinds' => ['client']]))->assertRedirect();
        $this->actingAs($manager)->post('/shipping-companies', $this->payload(['name' => 'Also makes things', 'kinds' => ['supplier']]))->assertRedirect();

        $both = Party::where('name', 'Buys and sells')->first();
        $this->assertSame('C-0001', $both->code);
        $this->assertTrue($both->is_client && $both->is_supplier);
        $this->assertSame('S-0001', Party::where('name', 'Also makes things')->value('code'));
    }

    public function test_an_update_never_changes_the_code_and_is_audited(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $this->actingAs($manager)->post('/clients', $this->payload())->assertRedirect();
        $party = Party::first();

        $this->actingAs($manager)->put("/clients/{$party->id}", $this->payload(['name' => 'Renamed LLC', 'code' => 'HACKED', 'deposit_percent' => '40']))
            ->assertRedirect(route('clients.show', $party));

        $party->refresh();
        $this->assertSame('C-0001', $party->code);
        $this->assertSame('Renamed LLC', $party->name);
        $this->assertSame('40.00', $party->deposit_percent);
        $audit = AuditLog::where('model_id', $party->id)->where('model_type', Party::class)->where('action', 'updated')->firstOrFail();
        $this->assertSame('Gulf Trading LLC', $audit->old_values['name']);
        $this->assertSame('Renamed LLC', $audit->new_values['name']);
        $this->assertArrayNotHasKey('code', $audit->new_values);
    }

    public function test_the_lists_are_checked(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/clients', $this->payload(['country' => 'XX', 'currency' => 'ZZZ', 'stage' => 'nonsense', 'deposit_percent' => '120']))
            ->assertSessionHasErrors(['country', 'currency', 'stage', 'deposit_percent']);
        $this->assertSame(0, Party::count());
    }

    public function test_another_kind_is_set_only_by_someone_who_may_edit_that_screen(): void
    {
        $sales = $this->make(Roles::SALES);       // clients.edit, no suppliers.edit
        $merch = $this->make(Roles::MERCHANDISER); // suppliers.edit, clients.view only

        $this->actingAs($sales)->post('/clients', $this->payload(['name' => 'Sales tick', 'kinds' => ['supplier'], 'factory_address' => 'Booth 1']))->assertRedirect();
        $party = Party::where('name', 'Sales tick')->first();
        $this->assertFalse($party->is_supplier);
        $this->assertNull($party->factory_address);

        $this->actingAs($merch)->post('/suppliers', $this->payload(['name' => 'Merch tick', 'kinds' => ['client'], 'account_manager_id' => $sales->id]))->assertRedirect();
        $party = Party::where('name', 'Merch tick')->first();
        $this->assertFalse($party->is_client);
        $this->assertNull($party->account_manager_id);
    }

    public function test_deactivating_and_reactivating_a_party(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $party = Party::factory()->supplier()->create(['name' => 'Sleepy Factory']);

        $this->actingAs($manager)->post("/suppliers/{$party->id}/deactivate")->assertRedirect(route('suppliers.show', $party));
        $this->assertFalse($party->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Party::class, 'model_id' => $party->id, 'action' => 'deactivated']);
        $this->actingAs($manager)->get('/suppliers')->assertDontSee('Sleepy Factory');
        $this->actingAs($manager)->get('/suppliers?active=0')->assertSee('Sleepy Factory');

        $this->actingAs($manager)->post("/suppliers/{$party->id}/reactivate")->assertRedirect();
        $this->assertTrue($party->fresh()->active);
        $this->assertDatabaseHas('audit_logs', ['model_type' => Party::class, 'model_id' => $party->id, 'action' => 'reactivated']);
    }

    // ---- search and filters --------------------------------------------------------

    public function test_the_index_searches_code_names_and_contact_phones_and_filters(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $a = Party::factory()->client()->create(['name' => 'Alpha Imports', 'name_local' => 'ألفا', 'country' => 'SA', 'stage' => 'lead', 'account_manager_id' => $manager->id]);
        $b = Party::factory()->client()->create(['name' => 'Beta Traders', 'country' => 'EG', 'stage' => 'inquiry']);
        PartyContact::create(['party_id' => $b->id, 'name' => 'Omar', 'phone' => '+201001234567']);

        $this->actingAs($manager)->get('/clients?search=alpha')->assertSee('Alpha Imports')->assertDontSee('Beta Traders');
        $this->actingAs($manager)->get(route('clients.index', ['search' => 'ألفا']))->assertSee('Alpha Imports')->assertDontSee('Beta Traders');
        $this->actingAs($manager)->get('/clients?search='.$b->code)->assertSee('Beta Traders')->assertDontSee('Alpha Imports');
        $this->actingAs($manager)->get(route('clients.index', ['search' => '100 123 45']))->assertSee('Beta Traders')->assertDontSee('Alpha Imports');
        $this->actingAs($manager)->get('/clients?country=EG')->assertSee('Beta Traders')->assertDontSee('Alpha Imports');
        $this->actingAs($manager)->get('/clients?stage=lead')->assertSee('Alpha Imports')->assertDontSee('Beta Traders');
        $this->actingAs($manager)->get('/clients?manager='.$manager->id)->assertSee('Alpha Imports')->assertDontSee('Beta Traders');
    }

    // ---- visibility (spec §6) ------------------------------------------------------

    public function test_a_sales_user_sees_only_their_own_clients(): void
    {
        $mine = $this->make(Roles::SALES);
        $theirs = $this->make(Roles::SALES);
        $manager = $this->make(Roles::MANAGER);
        $own = Party::factory()->client()->create(['name' => 'My Own Client', 'account_manager_id' => $mine->id]);
        $other = Party::factory()->client()->create(['name' => 'Somebody Elses Client', 'account_manager_id' => $theirs->id]);

        $this->actingAs($mine)->get('/clients')->assertOk()->assertSee('My Own Client')->assertDontSee('Somebody Elses Client');
        $this->actingAs($mine)->get("/clients/{$own->id}")->assertOk();
        $this->actingAs($mine)->get("/clients/{$other->id}")->assertNotFound();
        $this->actingAs($mine)->get("/clients/{$other->id}/edit")->assertNotFound();
        $this->actingAs($mine)->put("/clients/{$other->id}", $this->payload())->assertNotFound();
        $this->actingAs($mine)->post("/clients/{$other->id}/contacts", ['name' => 'Spy'])->assertNotFound();
        $this->actingAs($mine)->post(route('crm.interactions.store', $other), ['channel' => 'call', 'happened_at' => now()->toDateTimeString(), 'summary' => 'x'])->assertNotFound();
        $this->assertSame('Somebody Elses Client', $other->fresh()->name);

        $this->actingAs($manager)->get('/clients')->assertSee('My Own Client')->assertSee('Somebody Elses Client');
    }

    public function test_a_sales_user_manages_every_client_they_add(): void
    {
        $sales = $this->make(Roles::SALES);
        $colleague = $this->make(Roles::SALES);

        $response = $this->actingAs($sales)->post('/clients', $this->payload(['account_manager_id' => $colleague->id]));

        $party = Party::firstOrFail();
        $this->assertSame($sales->id, $party->account_manager_id);
        $response->assertRedirect(route('clients.show', $party));
    }

    public function test_supplier_bank_details_are_hidden_from_sales(): void
    {
        $sales = $this->make(Roles::SALES);
        $accountant = $this->make(Roles::ACCOUNTANT);
        $party = Party::factory()->create([
            'is_client' => true, 'is_supplier' => true, 'account_manager_id' => $sales->id,
            'factory_address' => 'Booth 42, District 2', 'bank_details' => 'ICBC 6222-SECRET-0001',
        ]);

        $this->actingAs($sales)->get("/clients/{$party->id}")->assertOk()->assertSee('Booth 42')->assertDontSee('6222-SECRET-0001');
        $this->actingAs($sales)->get("/clients/{$party->id}/edit")->assertOk()->assertDontSee('6222-SECRET-0001')->assertDontSee('name="bank_details"', false);

        // An update by sales cannot touch them, even when sent.
        $this->actingAs($sales)->put("/clients/{$party->id}", $this->payload(['bank_details' => 'OVERWRITTEN', 'factory_address' => 'Moved']))->assertRedirect();
        $party->refresh();
        $this->assertSame('ICBC 6222-SECRET-0001', $party->bank_details);
        $this->assertSame('Booth 42, District 2', $party->factory_address);
        $this->assertTrue($party->is_supplier, 'sales cannot take the supplier kind away');

        $this->actingAs($accountant)->get("/suppliers/{$party->id}")->assertOk()->assertSee('6222-SECRET-0001');
    }

    public function test_bank_details_never_reach_the_audit_log(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post('/suppliers', $this->payload(['bank_details' => 'HSBC 999-PRIVATE']))->assertRedirect();

        $this->assertStringNotContainsString('999-PRIVATE', (string) AuditLog::where('model_type', Party::class)->first()->getRawOriginal('new_values'));
        $this->assertSame('HSBC 999-PRIVATE', Party::first()->bank_details);
    }

    // ---- related documents ---------------------------------------------------------

    public function test_the_party_page_lists_its_documents_for_those_who_may_see_them(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $staffWithClients = $this->make(Roles::STAFF);
        $staffWithClients->givePermissionTo('clients.view');
        $client = Party::factory()->client()->create();
        ProformaInvoice::factory()->create(['client_id' => $client->id, 'pi_number' => 'PI-2026-0042']);
        $supplier = Party::factory()->supplier()->create();
        SupplierOrder::factory()->create(['supplier_id' => $supplier->id, 'number' => 'SO-2026-0007']);
        $forwarder = Party::factory()->shippingCompany()->create();
        Shipment::factory()->create(['shipping_company_id' => $forwarder->id, 'number' => 'SH-2026-0003']);

        $this->actingAs($manager)->get("/clients/{$client->id}")->assertOk()->assertSee('PI-2026-0042');
        $this->actingAs($manager)->get("/suppliers/{$supplier->id}")->assertOk()->assertSee('SO-2026-0007');
        $this->actingAs($manager)->get("/shipping-companies/{$forwarder->id}")->assertOk()->assertSee('SH-2026-0003');

        $this->actingAs($staffWithClients)->get("/clients/{$client->id}")->assertOk()->assertDontSee('PI-2026-0042');
    }
}
