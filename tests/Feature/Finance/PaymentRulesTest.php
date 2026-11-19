<?php

namespace Tests\Feature\Finance;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\PaymentApproval;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\SupplierOrderLine;
use App\Security\Roles;
use App\Services\Finance\FxRateService;
use App\Services\Finance\MoneyAccountService;
use App\Services\Finance\PaymentQueries;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** PaymentService and PaymentQueries: the rules of spec §3.5, §4 (FX, approvals) and the plan's contracts. */
class PaymentRulesTest extends FinanceTestCase
{
    private function queries(): PaymentQueries
    {
        return app(PaymentQueries::class);
    }

    public function test_a_usd_client_receipt_is_numbered_linked_to_its_pi_and_audited(): void
    {
        $pi = ProformaInvoice::factory()->worth('5000')->issued()->create();
        $payment = $this->receipt($this->usd(), '3000', pi: $pi)->fresh();

        $this->assertSame('PAY-'.now()->year.'-0001', $payment->number);
        $this->assertSame('in', $payment->direction);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('1.000000', $payment->rate);
        $this->assertSame('3000.0000', $payment->book_amount);
        $this->assertSame($pi->id, $payment->proforma_invoice_id);
        $this->assertSame($pi->client_id, $payment->party_id);
        $this->assertTrue($this->queries()->isEffective($payment));
        $this->assertTrue(AuditLog::where('model_type', Payment::class)->where('model_id', $payment->id)->where('action', 'created')->exists());

        $this->assertSame('PAY-'.now()->year.'-0002', $this->receipt($this->usd(), '10')->number);
    }

    public function test_a_cny_receipt_needs_a_rate_and_takes_the_stored_one(): void
    {
        $cny = $this->cny();

        try {
            $this->receipt($cny, '7000');
            $this->fail('a CNY receipt without any rate must be refused');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rate', $e->errors());
        }
        $this->assertSame(0, Payment::count());

        $this->setRate('CNY', now()->subDays(3)->toDateString(), '0.138000');
        $this->setRate('CNY', now()->addDay()->toDateString(), '0.150000'); // later than the payment: not used
        $payment = $this->receipt($cny, '7000')->fresh();
        $this->assertSame('0.138000', $payment->rate);
        $this->assertSame('966.0000', $payment->book_amount);

        $typed = $this->receipt($cny, '1000', extra: ['rate' => '0.14'])->fresh();
        $this->assertSame('0.140000', $typed->rate, 'the rate obtained, when typed, wins over the table');
    }

    public function test_the_book_currency_always_has_rate_one(): void
    {
        $this->assertSame('1', app(FxRateService::class)->rateFor('USD', now()));
        $this->assertSame('1.000000', $this->receipt($this->usd(), '50', extra: ['rate' => '3'])->fresh()->rate);
    }

    public function test_an_outgoing_payment_awaits_approval_only_when_the_option_is_on(): void
    {
        $usd = $this->usd();
        $this->receipt($usd, '1000');
        $out = $this->supplierPayment($usd, '400');

        $this->assertSame('out', $out->direction);
        $this->assertFalse($this->queries()->isEffective($out));
        $this->assertSame('awaiting', $this->queries()->status($out));
        $this->assertSame('1000.0000', $this->queries()->accountBalances()[$usd->id]);
        $this->assertSame(['USD' => '1000.0000'], $this->queries()->cashPosition());
        $this->assertSame([$out->id], $this->queries()->whereAwaitingApproval(Payment::query())->pluck('id')->all());

        $this->setOptions(['payments_out_require_approval' => false]);
        $this->assertTrue($this->queries()->isEffective($out->fresh()));
        $this->assertSame('effective', $this->queries()->status($out->fresh()));
        $this->assertSame('600.0000', $this->queries()->accountBalances()[$usd->id]);
        $this->assertSame(['USD' => '600.0000'], $this->queries()->cashPosition());
        $this->assertSame(0, $this->queries()->whereAwaitingApproval(Payment::query())->count());
    }

    public function test_only_a_manager_approves_and_only_once(): void
    {
        $usd = $this->usd();
        $out = $this->supplierPayment($usd, '400');

        try {
            $this->payments()->approve($out, $this->user(Roles::ACCOUNTANT));
            $this->fail('an accountant cannot approve');
        } catch (AuthorizationException) {
        }

        $approval = $this->payments()->approve($out, $this->user(Roles::MANAGER), 'ok');
        $this->assertInstanceOf(PaymentApproval::class, $approval);
        $this->assertTrue($this->queries()->isEffective($out->fresh()));
        $this->assertSame('-400.0000', $this->queries()->accountBalances()[$usd->id]);
        $this->assertTrue(AuditLog::where('model_type', Payment::class)->where('model_id', $out->id)->where('action', 'approved')->exists());

        $this->expectException(ValidationException::class);
        $this->payments()->approve($out->fresh(), $this->user(Roles::MANAGER));
    }

    public function test_money_in_and_a_payment_reversed_before_approval_are_never_approved(): void
    {
        $usd = $this->usd();
        $manager = $this->user(Roles::MANAGER);
        $in = $this->receipt($usd, '100');
        $out = $this->supplierPayment($usd, '40');
        [$reversal] = $this->payments()->reverse($out, $this->user(Roles::ACCOUNTANT), 'never sent');

        foreach ([$in, $out->fresh(), $reversal] as $payment) {
            try {
                $this->payments()->approve($payment, $manager);
                $this->fail("payment {$payment->number} must not be approvable");
            } catch (ValidationException) {
            }
        }
        // The database refuses as well (payment_approvals_guard).
        foreach ([$in, $out, $reversal] as $payment) {
            $this->assertDatabaseRefuses(fn () => DB::table('payment_approvals')->insert(['payment_id' => $payment->id, 'approved_by' => $manager->id]));
        }
        $this->assertSame(0, PaymentApproval::count());
        $this->assertSame('100.0000', $this->queries()->accountBalances()[$usd->id], 'neither the payment nor its reversal ever counts');
    }

    public function test_a_reversal_nets_the_balance_to_zero_and_is_never_reversed(): void
    {
        $usd = $this->usd();
        $receipt = $this->receipt($usd, '1500');
        $this->assertSame('1500.0000', $this->queries()->accountBalances()[$usd->id]);

        [$reversal] = $this->payments()->reverse($receipt, $this->user(Roles::ACCOUNTANT), 'entered twice');
        $reversal = $reversal->fresh();
        $this->assertSame($receipt->id, $reversal->reverses_payment_id);
        $this->assertSame('entered twice', $reversal->note);
        $this->assertSame('-1500.0000', $reversal->signed_amount);
        $this->assertSame('0.0000', $this->queries()->accountBalances()[$usd->id]);
        $this->assertSame('reversed', $this->queries()->status($receipt->fresh()));
        $this->assertSame('reversal', $this->queries()->status($reversal));

        try {
            $this->payments()->reverse($reversal, $this->user(Roles::ACCOUNTANT), 'undo');
            $this->fail('a reversal cannot be reversed');
        } catch (ValidationException) {
        }
        try {
            $this->payments()->reverse($receipt->fresh(), $this->user(Roles::ACCOUNTANT), 'again');
            $this->fail('a payment is reversed once');
        } catch (ValidationException) {
        }
        $this->assertSame(2, Payment::count());
    }

    public function test_the_reversal_of_an_approved_payment_out_is_approved_in_its_turn(): void
    {
        $usd = $this->usd();
        $manager = $this->user(Roles::MANAGER);
        $this->receipt($usd, '1000');
        $out = $this->supplierPayment($usd, '300');
        $this->payments()->approve($out, $manager);
        $this->assertSame('700.0000', $this->queries()->accountBalances()[$usd->id]);

        [$reversal] = $this->payments()->reverse($out->fresh(), $this->user(Roles::ACCOUNTANT), 'paid the wrong factory');
        $reversal = $reversal->fresh();
        $this->assertFalse($this->queries()->isEffective($reversal), 'money out, reversal or not, counts once approved');
        $this->assertTrue($this->queries()->awaitsApproval($reversal));
        $this->assertSame([$reversal->id], $this->queries()->whereAwaitingApproval(Payment::query())->pluck('id')->all());
        $this->assertSame('700.0000', $this->queries()->accountBalances()[$usd->id]);

        $this->payments()->approve($reversal, $manager);
        $this->assertTrue($this->queries()->isEffective($reversal->fresh()));
        $this->assertSame('1000.0000', $this->queries()->accountBalances()[$usd->id]);
        $this->assertSame(0, $this->queries()->whereAwaitingApproval(Payment::query())->count());
    }

    public function test_a_transfer_between_currencies_makes_two_linked_rows_and_moves_both_balances(): void
    {
        $this->setOptions(['payments_out_require_approval' => false]);
        $usd = $this->usd();
        $cny = $this->cny();
        $this->receipt($usd, '5000');

        $legs = $this->payments()->transfer([
            'from_account_id' => $usd->id, 'to_account_id' => $cny->id, 'paid_on' => now()->toDateString(),
            'amount' => '1000', 'in_amount' => '7100', 'in_rate' => '0.140845', 'reference' => 'TT-88',
        ], $this->user(Roles::ACCOUNTANT));

        $out = $legs['out']->fresh();
        $in = $legs['in']->fresh();
        $this->assertSame(['transfer_out', 'out', 'USD', '1000.0000', '1.000000'], [$out->kind, $out->direction, $out->currency, $out->amount, $out->rate]);
        $this->assertSame(['transfer_in', 'in', 'CNY', '7100.0000', '0.140845'], [$in->kind, $in->direction, $in->currency, $in->amount, $in->rate]);
        $this->assertNotNull($out->transfer_group);
        $this->assertSame($out->transfer_group, $in->transfer_group);
        $this->assertNotSame($out->number, $in->number);

        $balances = $this->queries()->accountBalances();
        $this->assertSame('4000.0000', $balances[$usd->id]);
        $this->assertSame('7100.0000', $balances[$cny->id]);
        $this->assertSame(['CNY' => '7100.0000', 'USD' => '4000.0000'], $this->queries()->cashPosition());

        // Reversing either side reverses both.
        $reversals = $this->payments()->reverse($in, $this->user(Roles::ACCOUNTANT), 'bank returned it');
        $this->assertCount(2, $reversals);
        $balances = $this->queries()->accountBalances();
        $this->assertSame('5000.0000', $balances[$usd->id]);
        $this->assertSame('0.0000', $balances[$cny->id]);
    }

    public function test_a_transfer_in_one_currency_moves_the_same_amount_and_needs_two_accounts(): void
    {
        $usd = $this->usd();
        $other = MoneyAccount::factory()->create(['name' => 'Cash USD', 'kind' => 'cash', 'location' => 'mainland']);
        $by = $this->user(Roles::ACCOUNTANT);

        $legs = $this->payments()->transfer(['from_account_id' => $usd->id, 'to_account_id' => $other->id, 'paid_on' => now()->toDateString(), 'amount' => '250'], $by);
        $this->assertSame('250.0000', $legs['in']->fresh()->amount);

        foreach ([
            ['to_account_id' => $usd->id],
            ['to_account_id' => $other->id, 'in_amount' => '240'],
        ] as $bad) {
            try {
                $this->payments()->transfer($bad + ['from_account_id' => $usd->id, 'paid_on' => now()->toDateString(), 'amount' => '250'], $by);
                $this->fail('refused: '.json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(2, Payment::count());
    }

    public function test_paid_for_a_pi_number_sums_its_revisions_in_its_currency_without_reversed_receipts(): void
    {
        $this->setRate('CNY', now()->toDateString(), '0.14');
        [$first, $second] = $this->piWithTwoRevisions();
        $usd = $this->usd();

        $this->receipt($usd, '300', pi: $first);
        $this->receipt($usd, '700', pi: $second);
        $this->receipt($this->cny(), '1000', pi: $second); // another currency: not in the USD figure
        $mistake = $this->receipt($usd, '999', pi: $second);
        $this->payments()->reverse($mistake, $this->user(Roles::ACCOUNTANT), 'wrong PI');
        $this->receipt($usd, '50', pi: ProformaInvoice::factory()->worth('100')->issued()->create(['client_id' => $first->client_id])); // another PI

        $this->assertSame('1000.0000', $this->queries()->paidForPiNumber('PI-2026-0042', 'USD'));
        $this->assertSame('1000.0000', $this->queries()->paidForPiNumber('PI-2026-0042', 'CNY'));
        $this->assertSame('0.0000', $this->queries()->paidForPiNumber('PI-2026-9999', 'USD'));
    }

    public function test_paid_to_a_supplier_order_counts_effective_payments_in_its_currency(): void
    {
        $this->setRate('CNY', now()->toDateString(), '0.14');
        $order = SupplierOrder::factory()->create();
        SupplierOrderLine::factory()->create(['supplier_order_id' => $order->id, 'unit_price' => '100']); // 48,000
        $cny = $this->cny();
        $approved = $this->supplierPayment($cny, '3000', $order->supplier, ['supplier_order_id' => $order->id]);
        $this->payments()->approve($approved, $this->user(Roles::MANAGER));
        $this->supplierPayment($cny, '500', $order->supplier, ['supplier_order_id' => $order->id]); // awaiting approval

        $this->assertSame('3000.0000', $this->queries()->paidToSupplierOrder($order));
        $this->assertSame('0.0000', $this->queries()->paidToSupplierOrder($order, 'USD'));
    }

    public function test_links_must_fit_the_kind_of_payment(): void
    {
        $usd = $this->usd();
        $by = $this->user(Roles::ACCOUNTANT);
        $client = Party::factory()->client()->create();
        $supplier = Party::factory()->supplier()->create();
        $otherPi = ProformaInvoice::factory()->issued()->create();
        $order = SupplierOrder::factory()->create();
        $base = ['paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '10'];

        $cases = [
            'receipt from a supplier' => [['kind' => 'client_receipt', 'party_id' => $supplier->id], 'party_id'],
            "another client's PI" => [['kind' => 'client_receipt', 'party_id' => $client->id, 'proforma_invoice_id' => $otherPi->id], 'proforma_invoice_id'],
            'supplier payment without supplier' => [['kind' => 'supplier_payment'], 'party_id'],
            "another supplier's order" => [['kind' => 'supplier_payment', 'party_id' => $supplier->id, 'supplier_order_id' => $order->id], 'supplier_order_id'],
            'PI on a supplier payment' => [['kind' => 'supplier_payment', 'party_id' => $order->supplier_id, 'proforma_invoice_id' => $otherPi->id], 'proforma_invoice_id'],
            'expense without account' => [['kind' => 'expense'], 'counter_account_id'],
            'expense to a heading' => [['kind' => 'expense', 'counter_account_id' => Account::where('code', '6')->value('id')], 'counter_account_id'],
            'transfer kind on the payment form' => [['kind' => 'transfer_in'], 'kind'],
            'zero amount' => [['kind' => 'client_receipt', 'party_id' => $client->id, 'amount' => '0'], 'amount'],
        ];
        foreach ($cases as $name => [$data, $field]) {
            try {
                $this->payments()->record($data + $base, $by);
                $this->fail("refused: {$name}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors(), $name);
            }
        }

        $expense = $this->payments()->record(['kind' => 'expense', 'counter_account_id' => Account::where('code', '6101')->value('id')] + $base, $by);
        $this->assertSame('out', $expense->direction);
        $this->assertSame(0, Payment::where('kind', '<>', 'expense')->count());
    }

    public function test_an_inactive_account_takes_no_new_payment_but_its_payments_can_be_reversed(): void
    {
        $usd = $this->usd();
        $receipt = $this->receipt($usd, '100');
        app(MoneyAccountService::class)->update($usd, ['active' => false] + $usd->only(['name', 'kind', 'currency', 'location', 'ledger_account_id', 'details']));

        try {
            $this->receipt($usd, '5');
            $this->fail('an inactive account takes no new payment');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('money_account_id', $e->errors());
        }
        $this->assertCount(1, $this->payments()->reverse($receipt, $this->user(Roles::ACCOUNTANT), 'closed account'));
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        try {
            DB::transaction(fn () => $write());
            $this->fail('the database should refuse this write');
        } catch (QueryException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
    }
}
