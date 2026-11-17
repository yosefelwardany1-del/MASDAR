<?php

namespace Tests\Feature\Configuration;

use App\Models\AuditLog;
use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\OrderStepDefinition;
use App\Models\User;
use App\Security\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

class ConfigurationTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function make(string ...$roles): User
    {
        return User::factory()->create()->assignRole($roles);
    }

    private function optionsPayload(array $overrides = []): array
    {
        $s = BusinessSetting::current();

        return array_merge([
            'company_address' => $s->company_address, 'company_phone' => $s->company_phone,
            'pi_currency' => 'USD', 'supplier_currency' => 'CNY', 'book_currency' => 'USD',
            'default_deposit_percent' => '30', 'pi_terms_template' => $s->pi_terms_template,
            'step_warning_days' => 2, 'eta_warning_days' => 3,
            'pi_issue_requires_approval' => '1', 'payments_out_require_approval' => '1', 'sales_own_clients_only' => '1',
            'client_prefix' => 'C', 'supplier_prefix' => 'S', 'shipping_company_prefix' => 'SC', 'product_prefix' => 'P',
            'pi_prefix' => 'PI', 'supplier_order_prefix' => 'SO', 'shipment_prefix' => 'SH', 'payment_prefix' => 'PAY', 'journal_prefix' => 'JE',
        ], $overrides);
    }

    public function test_only_a_manager_changes_the_options_and_staff_cannot_see_them(): void
    {
        $this->actingAs($this->make(Roles::STAFF))->get(route('configuration.index'))->assertForbidden();
        $this->actingAs($this->make(Roles::SYSTEM_ADMIN))->get(route('configuration.index'))->assertForbidden();
        $this->actingAs($this->make(Roles::ACCOUNTANT))->put(route('configuration.options.update'), $this->optionsPayload())->assertForbidden();

        foreach (['options', 'lists', 'steps'] as $tab) {
            $this->actingAs($this->make(Roles::MANAGER))->get(route('configuration.index', ['tab' => $tab]))->assertOk();
        }
    }

    public function test_the_defaults_come_from_masdars_pi_until_saved(): void
    {
        $this->assertSame(0, BusinessSetting::count());
        $this->assertSame('30% DEPOSIT 70% FINAL PAYMENT BEFORE SHIPPING', BusinessSetting::current()->piTerms('30'));
        $this->assertStringContainsString('Liandu Building', BusinessSetting::current()->company_address);
    }

    public function test_options_are_saved_audited_and_switches_turn_off(): void
    {
        $this->actingAs($this->make(Roles::MANAGER))
            ->put(route('configuration.options.update'), $this->optionsPayload(['default_deposit_percent' => '40', 'pi_prefix' => 'MPI']))
            ->assertRedirect();

        $s = BusinessSetting::current();
        $this->assertSame('40.00', $s->default_deposit_percent);
        $this->assertSame('MPI', $s->pi_prefix);
        $this->assertFalse($s->sales_see_supplier_prices, 'an unchecked switch is off');
        $this->assertTrue($s->sales_own_clients_only);
        $this->assertSame('40% DEPOSIT 60% FINAL PAYMENT BEFORE SHIPPING', $s->piTerms($s->default_deposit_percent));
        $this->assertTrue(AuditLog::where('model_type', BusinessSetting::class)->exists());
    }

    public function test_a_list_entry_is_added_renamed_and_switched_off_but_its_code_stays(): void
    {
        $manager = $this->make(Roles::MANAGER);

        $this->actingAs($manager)->post(route('configuration.lookups.store'), [
            'list' => 'container_type', 'code' => '45HQ', 'name' => "45' high cube", 'name_ar' => 'حاوية 45 قدم', 'sort' => 35, 'meta_cbm' => '86', 'meta_max_kg' => '27000',
        ])->assertRedirect();
        $entry = Lookup::where('list', 'container_type')->where('code', '45HQ')->firstOrFail();
        $this->assertSame(['cbm' => 86, 'max_kg' => 27000], $entry->meta);

        $this->actingAs($manager)->put(route('configuration.lookups.update', $entry), ['name' => '45HC', 'sort' => 35])->assertRedirect();
        $this->assertFalse($entry->fresh()->active);
        $this->assertSame('45HQ', $entry->fresh()->code);
        $this->assertArrayNotHasKey('45HQ', Lookup::options('container_type'));

        $this->actingAs($manager)->post(route('configuration.lookups.store'), ['list' => 'container_type', 'code' => '45HQ', 'name' => 'dup', 'sort' => 1])
            ->assertSessionHasErrors('code');
    }

    public function test_steps_are_renamed_reordered_and_new_ones_added(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $step = OrderStepDefinition::where('code', 'artwork')->firstOrFail();

        $this->actingAs($manager)->put(route('configuration.steps.update', $step), [
            'name' => 'Artwork approved', 'name_ar' => 'اعتماد التصميم', 'sort' => 55, 'kind' => 'manual', 'due_offset_days' => 15, 'active' => '1', 'requires_attachment' => '1',
        ])->assertRedirect();
        $this->assertSame('Artwork approved', $step->fresh()->name);
        $this->assertTrue($step->fresh()->requires_attachment);

        $this->actingAs($manager)->post(route('configuration.steps.store'), [
            'code' => 'third_party_inspection', 'name' => 'Third-party inspection', 'sort' => 75, 'kind' => 'manual',
        ])->assertRedirect();
        $this->assertSame(12, OrderStepDefinition::count());
    }

    public function test_the_screen_speaks_the_users_language(): void
    {
        $manager = $this->make(Roles::MANAGER);
        $manager->update(['locale' => 'zh_CN']);

        $this->actingAs($manager)->get(route('configuration.index', ['tab' => 'lists', 'list' => 'container_status']))
            ->assertOk()->assertSee('dir="ltr"', false)->assertSee('已订舱');

        $manager->update(['locale' => 'ar']);
        $this->actingAs($manager)->get(route('dashboard'))->assertSee('dir="rtl"', false);
    }

    public function test_a_user_switches_their_language(): void
    {
        $user = $this->make(Roles::STAFF);

        $this->actingAs($user)->put(route('profile.locale'), ['locale' => 'en'])->assertRedirect();
        $this->assertSame('en', $user->fresh()->locale);
        $this->actingAs($user)->put(route('profile.locale'), ['locale' => 'fr'])->assertSessionHasErrors('locale');
    }
}
