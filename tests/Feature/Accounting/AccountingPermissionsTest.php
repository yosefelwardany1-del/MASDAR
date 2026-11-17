<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Security\Roles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §6: accounting is the manager's and the accountant's. The accountant keeps the books
 * (chart, drafts, the posting queue); posting or reversing an adjustment and closing a period
 * are a manager's approvals.
 */
class AccountingPermissionsTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    private const SCREENS = [
        'accounting.index', 'accounting.journal.index', 'accounting.posting-rules.index', 'accounting.periods.index',
        'finance-reports.index', 'finance-reports.trial-balance', 'finance-reports.profit-and-loss',
        'finance-reports.balance-sheet', 'finance-reports.account-ledger', 'finance-reports.party-statement',
        'finance-reports.cash-position',
    ];

    public function test_staff_is_refused_every_accounting_screen_and_write(): void
    {
        $staff = $this->userWith(Roles::STAFF);
        $entry = $this->draft($this->accountant());

        foreach ([...self::SCREENS, 'accounting.to-post.index', 'accounting.journal.create', 'accounting.accounts.create'] as $route) {
            $this->actingAs($staff)->get(route($route))->assertForbidden();
        }
        $this->actingAs($staff)->post(route('accounting.journal.store'), [])->assertForbidden();
        $this->actingAs($staff)->post(route('accounting.journal.post', $entry))->assertForbidden();
        $this->actingAs($staff)->post(route('accounting.periods.store'), [])->assertForbidden();
    }

    public function test_sales_sees_no_ledger(): void
    {
        $sales = $this->userWith(Roles::SALES);

        $this->actingAs($sales)->get(route('accounting.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('finance-reports.index'))->assertForbidden();
    }

    public function test_every_screen_renders_for_the_accountant_and_the_manager(): void
    {
        foreach ([$this->accountant(), $this->manager()] as $user) {
            foreach ([...self::SCREENS, 'accounting.to-post.index', 'accounting.journal.create', 'accounting.accounts.create'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
        }
    }

    public function test_the_accountant_drafts_but_cannot_post_reverse_or_close(): void
    {
        $accountant = $this->accountant();

        $this->actingAs($accountant)->post(route('accounting.journal.store'), [
            'entry_date' => '2026-11-10', 'description' => 'Office rent',
            'lines' => [
                ['account_id' => $this->account('6101')->id, 'debit' => '500'],
                ['account_id' => $this->account('1101')->id, 'credit' => '500'],
                ['account_id' => '', 'debit' => '', 'credit' => ''],
            ],
        ])->assertRedirect()->assertSessionHas('success');
        $entry = JournalEntry::query()->latest('id')->firstOrFail();
        $this->assertSame('draft', $entry->status);
        $this->assertCount(2, $entry->lines);

        $this->actingAs($accountant)->post(route('accounting.journal.post', $entry))->assertForbidden();
        $this->assertSame('draft', $entry->fresh()->status);

        $this->actingAs($accountant)->post(route('accounting.periods.store'), ['name' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'])
            ->assertSessionHas('success');
        $period = AccountingPeriod::query()->firstOrFail();
        $this->actingAs($accountant)->post(route('accounting.periods.close', $period))->assertForbidden();
        $this->assertNull($period->fresh()->closed_at);

        // The service enforces it too, whoever calls it.
        $this->actingAs($accountant);
        $this->expectException(AuthorizationException::class);
        $this->posting()->post($entry, $accountant);
    }

    public function test_the_manager_posts_reverses_and_closes(): void
    {
        $manager = $this->manager();
        $entry = $this->draft($this->accountant());

        $this->actingAs($manager)->post(route('accounting.journal.post', $entry))->assertSessionHas('success');
        $entry->refresh();
        $this->assertSame('posted', $entry->status);
        $this->assertMatchesRegularExpression('/^JE-2026-\d{4}$/', $entry->number);
        $this->assertDatabaseHas('audit_logs', ['model_type' => JournalEntry::class, 'model_id' => $entry->id, 'action' => 'posted']);

        $this->actingAs($manager)->post(route('accounting.journal.reverse', $entry), ['reverse_on' => '2026-11-11'])->assertSessionHas('success');
        $this->assertNotNull($entry->fresh()->reversal);

        $this->actingAs($manager)->post(route('accounting.periods.store'), ['name' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $period = AccountingPeriod::query()->firstOrFail();
        $this->actingAs($manager)->post(route('accounting.periods.close', $period))->assertSessionHas('success');
        $this->assertNotNull($period->fresh()->closed_at);
        $this->assertDatabaseHas('audit_logs', ['model_type' => AccountingPeriod::class, 'model_id' => $period->id, 'action' => 'closed']);

        $this->actingAs($manager)->post(route('accounting.periods.reopen', $period))->assertSessionHas('success');
        $this->assertNull($period->fresh()->closed_at);
    }

    public function test_periods_never_overlap(): void
    {
        $accountant = $this->accountant();
        $this->actingAs($accountant)->post(route('accounting.periods.store'), ['name' => 'Nov', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);

        $this->actingAs($accountant)->from(route('accounting.periods.index'))
            ->post(route('accounting.periods.store'), ['name' => 'Late Nov', 'starts_on' => '2026-11-20', 'ends_on' => '2026-12-15'])
            ->assertRedirect(route('accounting.periods.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Nov'));
        $this->assertSame(1, AccountingPeriod::query()->count());
    }
}
