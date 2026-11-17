<?php

namespace Tests\Feature\Parties;

use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §3.1: contacts per party — name, role, international phone, WhatsApp, WeChat, e-mail. */
class PartyContactsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function contact(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Mr. Wang', 'role' => 'Sales manager', 'phone' => '+86 130 8565 0160',
            'whatsapp' => '+86 130 8565 0160', 'wechat' => 'wang_yiwu', 'email' => 'wang@example.test', 'is_primary' => '1',
        ], $overrides);
    }

    public function test_contacts_are_added_edited_and_deleted_and_each_change_is_audited(): void
    {
        $merch = $this->make(Roles::MERCHANDISER);
        $supplier = Party::factory()->supplier()->create();

        $this->actingAs($merch)->post("/suppliers/{$supplier->id}/contacts", $this->contact())
            ->assertRedirect(route('suppliers.show', $supplier).'#contacts');
        $contact = PartyContact::firstOrFail();
        $this->assertSame($supplier->id, $contact->party_id);
        $this->assertSame('+8613085650160', $contact->phone, 'stored in E.164');
        $this->assertSame('wang_yiwu', $contact->wechat);
        $this->assertTrue($contact->is_primary);
        $this->actingAs($merch)->get("/suppliers/{$supplier->id}")->assertSee('Mr. Wang')->assertSee('+8613085650160');

        $this->actingAs($merch)->put("/suppliers/{$supplier->id}/contacts/{$contact->id}", $this->contact(['name' => 'Mr. Wang Wei', 'phone' => '', 'is_primary' => '0']))
            ->assertRedirect();
        $contact->refresh();
        $this->assertSame('Mr. Wang Wei', $contact->name);
        $this->assertNull($contact->phone);
        $this->assertFalse($contact->is_primary);

        $this->actingAs($merch)->delete("/suppliers/{$supplier->id}/contacts/{$contact->id}")->assertRedirect();
        $this->assertSame(0, PartyContact::count());

        $this->assertSame(['created', 'updated', 'deleted'], AuditLog::where('model_type', PartyContact::class)->orderBy('id')->pluck('action')->all());
        $this->assertSame('Mr. Wang Wei', AuditLog::where('action', 'deleted')->first()->old_values['name']);
    }

    public function test_one_primary_contact_per_party(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $client = Party::factory()->client()->create();

        $this->actingAs($manager)->post("/clients/{$client->id}/contacts", $this->contact(['name' => 'First']));
        $this->actingAs($manager)->post("/clients/{$client->id}/contacts", $this->contact(['name' => 'Second']));

        $this->assertSame(['Second'], PartyContact::where('is_primary', true)->pluck('name')->all());

        $first = PartyContact::where('name', 'First')->first();
        $this->actingAs($manager)->put("/clients/{$client->id}/contacts/{$first->id}", $this->contact(['name' => 'First']));
        $this->assertSame(['First'], PartyContact::where('is_primary', true)->pluck('name')->all());
    }

    public function test_a_phone_without_its_country_code_is_refused_not_guessed(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $client = Party::factory()->client()->create();

        $this->actingAs($manager)->from("/clients/{$client->id}")->post("/clients/{$client->id}/contacts", $this->contact(['phone' => '0101 234 5678']))
            ->assertRedirect("/clients/{$client->id}")
            ->assertSessionHasErrorsIn('contact', ['phone']);
        $this->assertSame(0, PartyContact::count());

        // Arabic-Indic digits with 00 are read as the number they are.
        $this->actingAs($manager)->post("/clients/{$client->id}/contacts", $this->contact(['phone' => '٠٠٩٧١٥٠١٢٣٤٥٦٧', 'whatsapp' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('+971501234567', PartyContact::first()->phone);
    }

    public function test_a_contact_belongs_to_the_party_in_the_url(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $one = Party::factory()->client()->create();
        $two = Party::factory()->client()->create();
        $contact = PartyContact::create(['party_id' => $two->id, 'name' => 'Not yours']);

        $this->actingAs($manager)->put("/clients/{$one->id}/contacts/{$contact->id}", $this->contact())->assertNotFound();
        $this->actingAs($manager)->delete("/clients/{$one->id}/contacts/{$contact->id}")->assertNotFound();
        $this->assertSame('Not yours', $contact->fresh()->name);
    }

    public function test_contacts_need_the_party_screens_edit_permission(): void
    {
        $sales = $this->make(Roles::SALES);
        $accountant = $this->make(Roles::ACCOUNTANT); // suppliers: view
        $supplier = Party::factory()->supplier()->create();
        $contact = PartyContact::create(['party_id' => $supplier->id, 'name' => 'Keep me']);

        $this->actingAs($sales)->post("/suppliers/{$supplier->id}/contacts", $this->contact())->assertForbidden();
        $this->actingAs($accountant)->post("/suppliers/{$supplier->id}/contacts", $this->contact())->assertForbidden();
        $this->actingAs($accountant)->delete("/suppliers/{$supplier->id}/contacts/{$contact->id}")->assertForbidden();
        $this->actingAs($accountant)->get("/suppliers/{$supplier->id}")->assertOk()->assertSee('Keep me')->assertDontSee(route('suppliers.contacts.store', $supplier));
        $this->assertSame(1, PartyContact::count());
    }
}
