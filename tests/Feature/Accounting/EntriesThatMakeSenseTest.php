<?php

namespace Tests\Feature\Accounting;

use App\Models\BusinessSetting;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\PostingRule;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Finance\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * B29: an entry moves an amount from one account to another. A manual entry with every line on
 * one account is refused, and a payment names an account that fits its kind — never a money
 * account's own ledger account.
 */
class EntriesThatMakeSenseTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_a_manual_entry_on_one_account_is_refused(): void
    {
        $manager = $this->manager();
        $this->travelTo('2026-11-19 10:00:00');
        $refused = 'Every line is on account 1101 — Hong Kong bank — USD. An entry moves an amount from one account to another: put the other side on the account it comes from or goes to.';

        // JE-2026-0007: Dr 1101 / Cr 1101.
        $this->assertThrows(fn () => $this->draft($manager, '1101', '1101'), AccountingException::class, $refused);
        $this->actingAs($manager)->post(route('accounting.journal.store'), ['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('1101')->id, 'debit' => '250'],
            ['account_id' => $this->account('1101')->id, 'credit' => '250'],
        ]])->assertSessionHas('error', $refused);
        $this->assertSame(0, JournalEntry::count());

        // Two clients of one account is a real entry: a receipt moved from one client to another.
        [$a, $b] = Party::factory()->client()->count(2)->create();
        $move = $this->posting()->createDraft(['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('1401')->id, 'party_id' => $a->id, 'debit' => '80'],
            ['account_id' => $this->account('1401')->id, 'party_id' => $b->id, 'credit' => '80'],
        ]], $manager);
        $this->assertSame('posted', $this->posting()->post($move, $manager)->status);

        // The database refuses to post one that got past the form, with a plain message.
        $draft = $this->draft($manager);
        DB::table('journal_lines')->where('journal_entry_id', $draft->id)->update(['account_id' => $this->account('6501')->id]);
        $this->assertThrows(fn () => $this->posting()->post($draft->fresh(), $manager), AccountingException::class,
            'Every line of the entry is on the same account. An entry moves an amount from one account to another.');
    }

    public function test_a_posting_rule_debits_and_credits_two_accounts(): void
    {
        $rule = PostingRule::query()->where('event', 'client_invoice')->sole();
        $same = $this->account('1401')->id;
        $this->assertThrows(fn () => app(ChartOfAccountsService::class)->updatePostingRule($rule, ['debit_account_id' => $same, 'credit_account_id' => $same], $this->manager()),
            AccountingException::class, 'A posting rule debits one account and credits another: choose two different accounts.');
    }

    public function test_a_payment_names_an_account_that_fits_its_kind(): void
    {
        $manager = $this->manager();
        $usd = MoneyAccount::factory()->create();
        $base = ['paid_on' => now()->toDateString(), 'money_account_id' => $usd->id, 'amount' => '120'];

        // The form offers expense accounts for an expense, and never a money account's own account.
        $page = $this->actingAs($manager)->get(route('payments.create', ['kind' => 'expense']))->assertOk();
        $offered = $page->viewData('counterAccounts')->pluck('code')->all();
        $this->assertContains('6101', $offered);
        $this->assertNotContains('1101', $offered, 'the money account\'s own ledger account');
        $this->assertNotContains('1201', $offered, 'nor another money account\'s');
        $this->assertNotContains('4201', $offered, 'an income account is not an expense');
        $in = $this->actingAs($manager)->get(route('payments.create', ['kind' => 'other_in']))->viewData('counterAccounts')->pluck('code')->all();
        $this->assertContains('4201', $in);
        $this->assertContains('2501', $in, 'money lent by a director');
        $this->assertNotContains('6101', $in);

        // PAY-2026-0014: an expense "for" 1101, the account it was paid from.
        $this->actingAs($manager)->post(route('payments.store'), ['kind' => 'expense', 'counter_account_id' => $this->account('1101')->id] + $base)
            ->assertSessionHasErrors(['counter_account_id' => 'Account 1101 — Hong Kong bank — USD is the ledger account of a money account. Choose the account the money is for; to move money between your own accounts, record a transfer.']);
        $this->assertThrows(fn () => app(PaymentService::class)->record(['kind' => 'expense', 'counter_account_id' => $this->account('4201')->id] + $base, $manager),
            ValidationException::class, 'A payment of kind “Expense” takes an account of type Expense. 4201 — Commission income is of type Income.');
        $this->assertNotNull(app(PaymentService::class)->record(['kind' => 'expense', 'counter_account_id' => $this->account('6101')->id] + $base, $manager)->id);

        // The types are a setting (Lists & options): ticking Asset lets other money in name a deposit returned.
        $this->assertThrows(fn () => app(PaymentService::class)->record(['kind' => 'other_in', 'counter_account_id' => $this->account('1701')->id] + $base, $manager),
            ValidationException::class);
        $this->actingAs($manager)->put(route('configuration.options.update'), BusinessSetting::current()->only(['pi_currency', 'supplier_currency', 'book_currency',
            'default_deposit_percent', 'pi_terms_template', 'step_warning_days', 'eta_warning_days']) + ['counter_types_other_in' => ['income', 'liability', 'equity', 'asset', '']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['asset', 'liability', 'equity', 'income'], BusinessSetting::current()->counterAccountTypes('other_in'));
        $this->assertNotNull(app(PaymentService::class)->record(['kind' => 'other_in', 'counter_account_id' => $this->account('1701')->id] + $base, $manager)->id);
        $this->actingAs($manager)->put(route('configuration.options.update'), BusinessSetting::current()->only(['pi_currency', 'supplier_currency', 'book_currency',
            'default_deposit_percent', 'pi_terms_template', 'step_warning_days', 'eta_warning_days']) + ['counter_types_expense' => ['']])
            ->assertSessionHasErrors(['counter_types_expense' => 'Tick at least one type of account for Expense.']);

        // The database is the gate: a raw insert naming the money account's own account is refused.
        DB::beginTransaction();
        try {
            DB::table('payments')->insert(['number' => 'PAY-X', 'paid_on' => now()->toDateString(), 'kind' => 'expense', 'direction' => 'out',
                'money_account_id' => $usd->id, 'currency' => 'USD', 'amount' => '1', 'rate' => '1', 'user_id' => $manager->id,
                'counter_account_id' => $this->account('1101')->id]);
            $this->fail('the database accepted a payment for its own money account');
        } catch (QueryException $e) {
            $this->assertStringContainsString('own ledger account', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }
}
