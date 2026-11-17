<?php

namespace Tests\Feature\Invariants;

use App\Models\Account;
use App\Models\Container;
use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrderLine;
use App\Models\User;
use App\Services\Numbering\NumberingService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §1: each invariant is held by PostgreSQL, whoever writes. These tests go straight at
 * the tables — no service — to prove the database is the gate.
 */
class DatabaseInvariantsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function assertRefused(callable $write, string $message = ''): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('the database accepted a write it must refuse '.$message);
        } catch (QueryException $e) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_i4_an_issued_pi_and_its_lines_never_change(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $line = PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 1]);
        $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $pi->created_by]);

        $this->assertRefused(fn () => DB::table('proforma_invoices')->where('id', $pi->id)->update(['po_number' => 'X']), 'content change');
        $this->assertRefused(fn () => DB::table('pi_lines')->where('id', $line->id)->update(['cartons' => 99]), 'line change');
        $this->assertRefused(fn () => PiLine::factory()->create(['proforma_invoice_id' => $pi->id, 'line_no' => 2]), 'new line');
        $this->assertRefused(fn () => DB::table('proforma_invoices')->where('id', $pi->id)->delete(), 'delete');
        $this->assertRefused(fn () => DB::table('proforma_invoices')->where('id', $pi->id)->update(['status' => 'draft']), 'back to draft');

        DB::table('proforma_invoices')->where('id', $pi->id)->update(['status' => 'confirmed', 'confirmed_at' => now()]);
        $this->assertSame('confirmed', $pi->fresh()->status);
    }

    public function test_i4_only_one_current_revision_per_pi_number(): void
    {
        $pi = ProformaInvoice::factory()->create();
        $this->assertRefused(fn () => ProformaInvoice::factory()->create(['pi_number' => $pi->pi_number, 'revision' => 2, 'is_current' => true]));
    }

    public function test_i5_line_totals_are_computed_by_the_database(): void
    {
        $line = PiLine::factory()->create(['cartons' => 12, 'qty_per_carton' => 24, 'unit_price' => '0.8750', 'cbm_per_carton' => '0.0420', 'kg_per_carton' => '8.2000'])->fresh();

        $this->assertSame('288.0000', $line->total_qty);
        $this->assertSame('252.0000', $line->amount);
        $this->assertSame('0.5040', $line->total_cbm);
        $this->assertSame('98.4000', $line->total_kg);
    }

    public function test_i6_cannot_load_more_than_received(): void
    {
        $user = User::factory()->create();
        $line = SupplierOrderLine::factory()->create();
        $container = Container::factory()->create();
        GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now(), 'cartons' => 6, 'inspection_result' => 'pass', 'user_id' => $user->id]);

        DB::table('container_loads')->insert(['container_id' => $container->id, 'supplier_order_line_id' => $line->id, 'cartons' => 4, 'loaded_on' => now(), 'user_id' => $user->id]);
        $this->assertRefused(fn () => DB::table('container_loads')->insert(['container_id' => $container->id, 'supplier_order_line_id' => $line->id, 'cartons' => 3, 'loaded_on' => now(), 'user_id' => $user->id]));
        $this->assertRefused(fn () => GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now(), 'cartons' => -7, 'inspection_result' => 'pass', 'user_id' => $user->id]), 'receipts below zero');
    }

    public function test_i8_container_status_moves_forward_unless_corrected(): void
    {
        $user = User::factory()->create();
        $container = Container::factory()->create();
        $insert = fn (string $status, ?int $corrects = null) => DB::table('container_events')->insertGetId([
            'container_id' => $container->id, 'status' => $status, 'happened_at' => now(), 'user_id' => $user->id, 'corrects_event_id' => $corrects,
        ]);

        $loaded = $insert('loaded_sealed');
        $this->assertRefused(fn () => $insert('booked'), 'backwards');
        $this->assertRefused(fn () => $insert('no_such_status'), 'unknown');
        $insert('loading', $loaded);
        $this->assertSame(2, DB::table('container_events')->where('container_id', $container->id)->count());
    }

    public function test_i2_logs_are_append_only(): void
    {
        $user = User::factory()->create();
        $line = SupplierOrderLine::factory()->create();
        $receipt = GoodsReceipt::create(['supplier_order_line_id' => $line->id, 'received_on' => now(), 'cartons' => 5, 'inspection_result' => 'pass', 'user_id' => $user->id]);

        $this->assertRefused(fn () => DB::table('goods_receipts')->where('id', $receipt->id)->update(['cartons' => 6]));
        $this->assertRefused(fn () => DB::table('goods_receipts')->where('id', $receipt->id)->delete());
    }

    public function test_payments_are_in_their_account_currency_and_never_change(): void
    {
        $user = User::factory()->create();
        $usd = MoneyAccount::factory()->create();
        $row = ['number' => 'PAY-T-1', 'paid_on' => now()->toDateString(), 'kind' => 'client_receipt', 'direction' => 'in', 'money_account_id' => $usd->id,
            'currency' => 'USD', 'amount' => 1000, 'rate' => 1, 'party_id' => Party::factory()->client()->create()->id, 'user_id' => $user->id];

        $this->assertRefused(fn () => DB::table('payments')->insert(['currency' => 'CNY'] + $row), 'wrong currency');
        $this->assertRefused(fn () => DB::table('payments')->insert(['direction' => 'out'] + $row), 'kind and direction disagree');

        $payment = Payment::create($row)->fresh();
        $this->assertSame('1000.0000', $payment->signed_amount);
        $this->assertRefused(fn () => DB::table('payments')->where('id', $payment->id)->update(['amount' => 1]));

        $reversal = Payment::create(['number' => 'PAY-T-2', 'reverses_payment_id' => $payment->id] + $row)->fresh();
        $this->assertSame('-1000.0000', $reversal->signed_amount);
    }

    public function test_i1_i3_a_journal_entry_posts_only_when_balanced_and_in_an_open_period(): void
    {
        $user = User::factory()->create();
        $cash = Account::where('code', '1101')->value('id');
        $sales = Account::where('code', '4101')->value('id');
        $entry = JournalEntry::create(['number' => 'JE-T-1', 'entry_date' => '2026-11-10', 'created_by' => $user->id]);
        $line = fn (int $account, string $debit, string $credit) => JournalLine::create([
            'journal_entry_id' => $entry->id, 'account_id' => $account, 'debit' => $debit, 'credit' => $credit,
            'currency' => 'USD', 'amount_currency' => max($debit, $credit), 'rate' => 1,
        ]);

        $line($cash, '100', '0');
        $line($sales, '0', '90');
        $this->assertRefused(fn () => $entry->update(['status' => 'posted']), 'unbalanced');

        $line($sales, '0', '10');
        $this->assertRefused(fn () => $line(Account::where('code', '11')->value('id'), '5', '0'), 'heading account');

        DB::table('accounting_periods')->insert(['name' => 'Nov', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30', 'closed_at' => now()]);
        $this->assertRefused(fn () => $entry->update(['status' => 'posted']), 'closed period');
        DB::table('accounting_periods')->update(['closed_at' => null]);

        $entry->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $user->id]);
        $this->assertRefused(fn () => $line($cash, '1', '0'), 'line on a posted entry');
        $this->assertRefused(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'x']), 'posted entry');
    }

    public function test_party_and_product_codes_never_change(): void
    {
        $party = Party::factory()->client()->create();
        $product = Product::factory()->create();

        $this->assertRefused(fn () => DB::table('parties')->where('id', $party->id)->update(['code' => 'C-9999']));
        $this->assertRefused(fn () => DB::table('products')->where('id', $product->id)->update(['code' => 'P-9999']));
        DB::table('parties')->where('id', $party->id)->update(['name' => 'Renamed']);
        $this->assertSame('Renamed', $party->fresh()->name);
    }

    public function test_numbers_are_gapless_per_series_and_year(): void
    {
        $numbers = app(NumberingService::class);

        $this->assertSame('PI-2026-0001', $numbers->next('pi', new \DateTime('2026-03-01')));
        $this->assertSame('PI-2026-0002', $numbers->next('pi', new \DateTime('2026-11-01')));
        $this->assertSame('PI-2027-0001', $numbers->next('pi', new \DateTime('2027-01-02')));
        $this->assertSame('C-0001', $numbers->next('client'));
    }
}
