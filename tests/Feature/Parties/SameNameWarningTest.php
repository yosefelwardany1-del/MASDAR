<?php

namespace Tests\Feature\Parties;

use App\Models\Account;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B23: a name already used (case and spacing aside) is said before saving; money accounts never repeat one. */
class SameNameWarningTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_a_second_client_or_product_of_the_same_name_needs_save_anyway(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $client = ['name' => 'TEST-Client B', 'country' => 'AE', 'city' => 'Dubai', 'currency' => 'USD', 'active' => '1', 'kinds_sent' => '1'];
        $this->actingAs($manager)->post('/clients', $client)->assertRedirect()->assertSessionHasNoErrors();
        $first = Party::where('name', 'TEST-Client B')->sole();

        $this->actingAs($manager)->from('/clients/create')->post('/clients', ['name' => '  test-client   b '] + $client)
            ->assertSessionHasErrors(['name' => 'TEST-Client B already exists ('.$first->code.'). Open it, or tick "Save anyway" to add another.']);
        $this->actingAs($manager)->get('/clients/create')->assertSee(route('clients.show', $first), false)->assertSee('Save anyway');
        $this->assertSame(1, Party::count());
        $this->actingAs($manager)->post('/clients', ['name' => 'TEST-Client B', 'save_anyway' => '1'] + $client)->assertSessionHasNoErrors();
        $this->assertSame(2, Party::where('is_client', true)->count());

        $product = ['name' => 'TEST-Prod Z', 'unit' => 'pcs', 'qty_per_carton' => '10', 'sale_currency' => 'USD', 'active' => '1'];
        $this->actingAs($manager)->post('/products', $product)->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/products', ['name' => 'test-prod z'] + $product)->assertSessionHasErrors('name');
        $this->assertSame(1, Product::count());
    }

    public function test_a_money_account_name_is_used_once_per_currency(): void
    {
        app()->setLocale('en');
        $accountant = User::factory()->create(['locale' => 'en'])->assignRole(Roles::ACCOUNTANT);
        $account = ['name' => 'TEST-USD Cash', 'kind' => 'cash', 'currency' => 'USD', 'location' => 'hong_kong', 'ledger_account_id' => Account::where('code', '1101')->value('id'), 'active' => '1'];
        $this->actingAs($accountant)->post(route('money-accounts.store'), $account)->assertSessionHasNoErrors();
        $this->actingAs($accountant)->post(route('money-accounts.store'), ['name' => 'test-usd  cash'] + $account)
            ->assertSessionHasErrors(['name' => 'There is already a USD account named test-usd  cash. Give this one another name.']);
        $this->assertSame(1, MoneyAccount::count());
    }
}
