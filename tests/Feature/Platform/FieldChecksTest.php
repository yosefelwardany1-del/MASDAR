<?php

namespace Tests\Feature\Platform;

use App\Models\Party;
use App\Models\Shipment;
use App\Models\User;
use App\Security\Roles;
use App\Services\Finance\ExpenseClaimService;
use App\Services\Hr\EmployeeService;
use App\Services\Hr\PayrollService;
use App\Services\Shipping\ShipmentService;
use App\Support\ContainerNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B28: container numbers, HS codes, dates that cannot be ahead, tracking addresses and salaries. */
class FieldChecksTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_a_container_number_is_iso_6346_and_an_hs_code_is_4_to_10_digits(): void
    {
        app()->setLocale('en');
        $this->assertTrue(ContainerNumber::isValid('MSKU7712342'));
        $this->assertTrue(ContainerNumber::isValid('CSQU3054383'));
        $this->assertFalse(ContainerNumber::isValid('MSKU7712345'), 'wrong check digit');
        $this->assertFalse(ContainerNumber::isValid('ABC'));

        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $shipment = Shipment::factory()->create();
        $this->assertThrows(fn () => app(ShipmentService::class)->addContainer($shipment, ['container_no' => 'ABC'], $manager), ValidationException::class, 'ABC is not a container number.');
        app(ShipmentService::class)->addContainer($shipment, ['container_no' => 'csqu 3054383'], $manager);
        app(ShipmentService::class)->addContainer($shipment, ['container_no' => null], $manager); // a number not known yet is fine

        $product = ['name' => 'Cup', 'unit' => 'pcs', 'qty_per_carton' => '10', 'sale_currency' => 'USD', 'active' => '1'];
        $this->actingAs($manager)->post('/products', ['hs_code' => 'ABC'] + $product)
            ->assertSessionHasErrors(['hs_code' => 'An HS code is 4 to 10 digits; dots may group them, e.g. 6912.00.']);
        $this->actingAs($manager)->post('/products', ['hs_code' => '6912.00'] + $product)->assertSessionHasNoErrors();
    }

    public function test_dates_that_cannot_be_ahead_and_a_second_salary_on_one_day(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $hr = User::factory()->create(['locale' => 'en'])->assignRole(Roles::HR);
        $people = app(EmployeeService::class);

        $this->assertThrows(fn () => $people->create(['name' => 'Li', 'joined_on' => '2025-01-01', 'birth_date' => '2030-01-01'], $hr), ValidationException::class, 'The birth date cannot be in the future.');
        $employee = $people->create(['name' => 'Li', 'joined_on' => '2025-01-01', 'birth_date' => '1990-05-01'], $hr);
        $people->addSalary($employee, ['effective_from' => '2025-01-01', 'base_salary' => '7000'], $manager);
        $this->assertThrows(fn () => $people->addSalary($employee, ['effective_from' => '2025-01-01', 'base_salary' => '7500'], $manager), ValidationException::class,
            'A salary from 2025-01-01 is already recorded.');

        $this->assertThrows(fn () => app(PayrollService::class)->prepare(['period' => '2030-01'], $hr), ValidationException::class, '2030-01 has not begun');
        $claim = app(ExpenseClaimService::class)->create(['currency' => 'CNY', 'title' => 'Trip'], $manager);
        $this->assertThrows(fn () => app(ExpenseClaimService::class)->addLine($claim, ['spent_on' => '2030-01-01', 'category' => 'travel', 'description' => 'Train', 'amount' => '50'], $manager),
            ValidationException::class, 'A receipt cannot be dated after today.');
    }

    public function test_a_tracking_address_takes_only_the_numbers_it_can_fill(): void
    {
        app()->setLocale('en');
        $manager = User::factory()->create(['locale' => 'en'])->assignRole(Roles::MANAGER);
        $company = ['name' => 'Ningbo Lines', 'country' => 'CN', 'city' => 'Ningbo', 'currency' => 'USD', 'active' => '1', 'kinds_sent' => '1'];
        $this->actingAs($manager)->post('/shipping-companies', ['tracking_url_template' => 'https://track.example.com/{number}'] + $company)
            ->assertSessionHasErrors(['tracking_url_template' => 'Only {container}, {bl} and {booking} can be filled in; {number} cannot.']);
        $this->actingAs($manager)->post('/shipping-companies', ['tracking_url_template' => 'https://track.example.com/'] + $company)
            ->assertSessionHasErrors(['tracking_url_template' => 'Put {container}, {bl} or {booking} where the number goes in the address.']);
        $this->actingAs($manager)->post('/shipping-companies', ['tracking_url_template' => 'https://track.example.com/{container}?bl={bl}'] + $company)->assertSessionHasNoErrors();
        $this->assertSame(1, Party::where('is_shipping', true)->count());
    }
}
