<?php

namespace Tests\Feature\Accounting;

use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\PaymentApproval;
use App\Models\PostingRule;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\PostingQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Payments post by rule, only once they are effective (phase-1 contract), each once. */
class PaymentPostingTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_a_client_receipt_in_cny_posts_to_the_money_account_and_the_receivable(): void
    {
        $accountant = $this->accountant();
        $client = Party::factory()->client()->create();
        $rmb = MoneyAccount::factory()->rmb()->create();
        $payment = $this->payment([
            'kind' => 'client_receipt', 'money_account_id' => $rmb->id, 'party_id' => $client->id,
            'amount' => '7000.0000', 'rate' => '0.140000', 'paid_on' => '2026-11-09',
        ]);

        $this->actingAs($accountant)->post(route('accounting.to-post.payment', $payment))->assertSessionHas('success');

        $entry = JournalEntry::query()->where('source_type', 'payment')->where('source_id', $payment->id)->firstOrFail();
        $this->assertSame('posted', $entry->status);
        $this->assertSame('client_receipt', $entry->event);
        $this->assertSame('2026-11-09', $entry->entry_date->toDateString());

        [$money, $receivable] = $entry->lines()->with('account')->get()->all();
        $this->assertSame('1201', $money->account->code);
        $this->assertSame('980.0000', $money->debit);
        $this->assertNull($money->party_id);
        $this->assertSame('1401', $receivable->account->code);
        $this->assertSame('980.0000', $receivable->credit);
        $this->assertSame($client->id, $receivable->party_id);
        foreach ([$money, $receivable] as $line) {
            $this->assertSame('CNY', $line->currency);
            $this->assertSame('7000.0000', $line->amount_currency);
            $this->assertSame('0.140000', $line->rate);
        }
        $this->assertDatabaseHas('audit_logs', ['model_type' => JournalEntry::class, 'model_id' => $entry->id, 'action' => 'posted']);
    }

    public function test_each_kind_takes_its_accounts(): void
    {
        $accountant = $this->accountant();
        $supplier = Party::factory()->supplier()->create();
        $shipper = $this->shippingCompany();
        $usd = MoneyAccount::factory()->create();
        $cases = [
            ['supplier_payment', ['party_id' => $supplier->id], '2201', '1101', $supplier->id],
            ['shipping_payment', ['party_id' => $shipper->id], '2301', '1101', $shipper->id],
            ['expense', ['counter_account_id' => $this->account('6101')->id], '6101', '1101', null],
            ['other_out', ['counter_account_id' => $this->account('2501')->id], '2501', '1101', null],
            ['other_in', ['counter_account_id' => $this->account('3101')->id], '1101', '3101', null],
            ['transfer_out', [], '1301', '1101', null],
            ['transfer_in', [], '1101', '1301', null],
        ];
        $this->paymentsOutNeedNoApproval();

        foreach ($cases as [$kind, $extra, $debit, $credit, $party]) {
            $payment = $this->payment(['kind' => $kind, 'money_account_id' => $usd->id] + $extra);
            $entry = $this->posting()->postPayment($payment, $accountant);
            $lines = $entry->lines()->with('account')->get();
            $this->assertSame([$debit, $credit], [$lines[0]->account->code, $lines[1]->account->code], $kind);
            $this->assertSame('1000.0000', $lines[0]->debit, $kind);
            $this->assertSame('1000.0000', $lines[1]->credit, $kind);
            $this->assertSame($party, $lines->firstWhere('party_id', '!==', null)?->party_id, $kind);
        }
    }

    public function test_an_outgoing_payment_awaiting_approval_is_not_offered_until_approved(): void
    {
        $accountant = $this->accountant();
        $manager = $this->manager();
        $payment = $this->payment(['kind' => 'supplier_payment', 'party_id' => Party::factory()->supplier()->create()->id]);
        $queue = app(PostingQueue::class);

        $this->assertFalse($queue->payments()->contains('id', $payment->id));
        $this->assertSame(1, $queue->awaitingApproval());
        $this->actingAs($accountant)->post(route('accounting.to-post.payment', $payment))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'awaiting approval'));
        $this->assertSame(0, JournalEntry::query()->count());

        PaymentApproval::create(['payment_id' => $payment->id, 'approved_by' => $manager->id]);

        $this->assertTrue($queue->payments()->contains('id', $payment->id));
        $this->actingAs($accountant)->get(route('accounting.to-post.index'))->assertOk()->assertSee($payment->number);
        $this->actingAs($accountant)->post(route('accounting.to-post.payments'))->assertSessionHas('success');
        $this->assertSame(1, JournalEntry::query()->where('source_id', $payment->id)->count());
        $this->assertFalse($queue->payments()->contains('id', $payment->id));
    }

    public function test_a_reversal_payment_posts_the_original_lines_swapped(): void
    {
        $accountant = $this->accountant();
        $client = Party::factory()->client()->create();
        $rmb = MoneyAccount::factory()->rmb()->create();
        $original = $this->payment(['kind' => 'client_receipt', 'money_account_id' => $rmb->id, 'party_id' => $client->id]);
        $reversal = $this->payment([
            'kind' => 'client_receipt', 'money_account_id' => $rmb->id, 'party_id' => $client->id,
            'reverses_payment_id' => $original->id, 'paid_on' => '2026-11-11',
        ]);

        // The reversal waits for its original.
        $this->expectsRefusal(fn () => $this->posting()->postPayment($reversal, $accountant), $original->number);

        $first = $this->posting()->postPayment($original, $accountant);
        $second = $this->posting()->postPayment($reversal, $accountant);

        $this->assertSame($first->id, $second->reverses_entry_id);
        $this->assertSame('payment', $second->source_type);
        $this->assertSame($reversal->id, $second->source_id);
        $this->assertSame('2026-11-11', $second->entry_date->toDateString());
        $a = $first->lines()->get();
        $b = $second->lines()->get();
        $this->assertSame([$a[0]->account_id, $a[0]->debit, $a[0]->credit], [$b[0]->account_id, $b[0]->credit, $b[0]->debit]);
        $this->assertSame([$a[1]->account_id, $a[1]->party_id, $a[1]->credit], [$b[1]->account_id, $b[1]->party_id, $b[1]->debit]);
        $this->assertSame('0.0000', $this->rawBalance('1401'));
        $this->assertSame('0.0000', $this->rawBalance('1201'));

        // A payment's entry is reversed through the payment, not by hand.
        $this->expectsRefusal(fn () => $this->posting()->reverse($first, $this->manager()), 'Payments screen');
    }

    public function test_a_missing_posting_rule_account_gives_the_named_message(): void
    {
        $accountant = $this->accountant();
        PostingRule::query()->where('event', 'client_receipt')->update(['credit_account_id' => null]);
        $payment = $this->payment(['kind' => 'client_receipt', 'party_id' => Party::factory()->client()->create()->id]);

        $this->actingAs($accountant)->from(route('accounting.to-post.index'))
            ->post(route('accounting.to-post.payment', $payment))
            ->assertRedirect(route('accounting.to-post.index'))
            ->assertSessionHas('error', 'Set the credit account of posting rule client_receipt on the Accounting screen.');
        $this->assertSame(0, JournalEntry::query()->count());

        PostingRule::query()->where('event', 'supplier_payment')->delete();
        $this->paymentsOutNeedNoApproval();
        $out = $this->payment(['kind' => 'supplier_payment']);
        $this->expectsRefusal(fn () => $this->posting()->postPayment($out, $accountant), 'Set up posting rule supplier_payment on the Accounting screen.');
    }

    public function test_a_money_account_without_a_ledger_account_gives_the_named_message(): void
    {
        $money = MoneyAccount::factory()->create(['name' => 'Petty cash Yiwu', 'ledger_account_id' => null]);
        $payment = $this->payment(['kind' => 'client_receipt', 'money_account_id' => $money->id]);

        $this->expectsRefusal(fn () => $this->posting()->postPayment($payment, $this->accountant()),
            'Set the ledger account of money account Petty cash Yiwu on the Payments screen.');
    }

    public function test_an_expense_without_a_counter_account_is_refused_with_what_to_do(): void
    {
        $this->paymentsOutNeedNoApproval();
        $payment = $this->payment(['kind' => 'expense']);

        $this->expectsRefusal(fn () => $this->posting()->postPayment($payment, $this->accountant()), 'no counter account');
    }

    public function test_a_payment_posts_only_once(): void
    {
        $accountant = $this->accountant();
        $payment = $this->payment(['kind' => 'client_receipt', 'party_id' => Party::factory()->client()->create()->id]);

        $this->posting()->postPayment($payment, $accountant);
        $this->expectsRefusal(fn () => $this->posting()->postPayment($payment, $accountant), 'already posted');
        $this->assertSame(1, JournalEntry::query()->count());

        // And the database holds the line whoever writes (the one-posting-per-source index).
        $this->assertDatabaseRefuses(fn () => DB::table('journal_entries')->insert([
            'number' => 'X-1', 'entry_date' => '2026-11-10', 'status' => 'draft', 'event' => 'client_receipt',
            'source_type' => 'payment', 'source_id' => $payment->id, 'created_by' => $accountant->id,
        ]));
    }

    public function test_a_trigger_refusal_becomes_a_message(): void
    {
        $manager = $this->manager();
        $entry = $this->posting()->post($this->draft($manager), $manager);

        try {
            DB::transaction(fn () => DB::table('journal_entries')->where('id', $entry->id)->update(['description' => 'x']));
            $this->fail('a posted entry changed');
        } catch (QueryException $e) {
            $this->assertSame('A posted entry never changes; reverse it instead.', AccountingException::fromQuery($e)->getMessage());
        }
    }

    private function expectsRefusal(callable $call, string $message): void
    {
        try {
            $call();
            $this->fail('the posting was not refused');
        } catch (AccountingException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    private function assertDatabaseRefuses(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('the database accepted a second posting of one source');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
