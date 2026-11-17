<?php

namespace Tests\Feature\Integration;

use App\Models\Container;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Services\Finance\PaymentQueries;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * The example data MASDAR is shown the system with goes through the real writers, so a change
 * to a writer's rules can break it; this keeps it working, with books that balance.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    public function test_the_demo_data_seeds_through_the_writers_and_its_books_balance(): void
    {
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);

        $this->assertSame(2, ProformaInvoice::where('status', '!=', 'draft')->count());
        $this->assertSame(2, SupplierOrder::count());
        $this->assertSame('on_board', Container::sole()->latestEvent->status);

        $this->assertSame(6, JournalEntry::where('status', 'posted')->count(), 'invoice, bill, deposit, both legs of the transfer, factory deposit');
        $totals = DB::table('journal_lines')->selectRaw('SUM(debit) AS dr, SUM(credit) AS cr')->first();
        $this->assertSame(0, bccomp((string) $totals->dr, (string) $totals->cr, 4), 'the journal balances');

        $queries = app(PaymentQueries::class);
        foreach (MoneyAccount::all() as $account) {
            $balance = Payment::where('money_account_id', $account->id)->get()
                ->filter(fn (Payment $p) => $queries->isEffective($p))
                ->reduce(fn (string $sum, Payment $p) => bcadd($sum, $p->direction === 'in' ? (string) $p->amount : '-'.$p->amount, 4), '0');
            $this->assertGreaterThanOrEqual(0, bccomp($balance, '0', 4), $account->name.' is never overdrawn');
        }

        $manager = User::where('email', 'manager@example.test')->sole();
        $this->actingAs($manager)->get('/')->assertOk();
        $this->actingAs($manager)->get(route('finance-reports.trial-balance'))->assertOk()->assertSee('1401');
    }
}
