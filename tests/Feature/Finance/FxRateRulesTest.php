<?php

namespace Tests\Feature\Finance;

use App\Models\FxRate;
use App\Security\Roles;
use App\Services\Accounting\Ledger;
use App\Services\Finance\FxRateService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** B08: rates are never dated ahead, "latest" is on or before today, an unused rate can go. */
class FxRateRulesTest extends FinanceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    public function test_a_future_rate_is_refused_and_never_taken_as_the_latest(): void
    {
        $manager = $this->user(Roles::MANAGER)->forceFill(['locale' => 'en']);
        $manager->save();
        $this->setRate('CNY', now()->toDateString(), '0.15');

        $this->assertThrows(fn () => app(FxRateService::class)->set('CNY', '2030-01-01', '0.5', $manager), ValidationException::class, 'A rate cannot be dated after today.');
        $this->actingAs($manager)->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => now()->addDay()->toDateString(), 'rate' => '0.5'])
            ->assertSessionHasErrors(['rate_date' => 'A rate cannot be dated after today.']);

        // One written before this rule (as on the live system) is ignored by "latest" and today's conversions.
        FxRate::create(['currency' => 'CNY', 'rate_date' => '2030-01-01', 'rate' => '0.5', 'created_by' => $manager->id]);
        $this->assertSame('0.150000', app(Ledger::class)->latestRates()['CNY']);
        $this->actingAs($manager)->get(route('fx-rates.index'))->assertOk()->assertSee('Latest rate · '.now()->toDateString());
    }

    public function test_saving_over_a_days_rate_says_it_was_replaced(): void
    {
        $manager = $this->user(Roles::MANAGER)->forceFill(['locale' => 'en']);
        $manager->save();
        $day = now()->subDay()->toDateString();
        $this->actingAs($manager)->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => $day, 'rate' => '0.14'])->assertSessionHas('success', 'Rate saved.');
        $this->actingAs($manager)->post(route('fx-rates.store'), ['currency' => 'CNY', 'rate_date' => $day, 'rate' => '0.15'])
            ->assertSessionHas('success', "The CNY rate of {$day} was 0.140000; it is now 0.150000.");
        $this->assertSame(1, FxRate::count());
    }

    public function test_a_rate_nothing_used_can_be_deleted_and_a_used_one_cannot(): void
    {
        $manager = $this->user(Roles::MANAGER)->forceFill(['locale' => 'en']);
        $manager->save();
        $this->setRate('CNY', now()->subDays(10)->toDateString(), '0.14');
        $unused = FxRate::where('currency', 'CNY')->sole();
        $this->actingAs($manager)->delete(route('fx-rates.destroy', $unused))->assertSessionHasNoErrors();
        $this->assertSame(0, FxRate::count());

        $this->setRate('CNY', now()->subDays(10)->toDateString(), '0.14');
        $used = FxRate::where('currency', 'CNY')->sole();
        $this->receipt($this->cny(), '700'); // takes 0.14, the rate in force
        $this->actingAs($manager)->get(route('fx-rates.index'))->assertDontSee('action="'.route('fx-rates.destroy', $used).'"', false);
        $this->actingAs($manager)->delete(route('fx-rates.destroy', $used))->assertSessionHasErrors('rate');
        DB::beginTransaction();
        try {
            DB::table('fx_rates')->where('id', $used->id)->delete();
            $this->fail('the database deleted a used rate');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
