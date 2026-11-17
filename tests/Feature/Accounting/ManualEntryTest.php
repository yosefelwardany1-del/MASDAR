<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\JournalEntry;
use App\Services\Accounting\AccountingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Manual (adjustment) entries: drafted, posted only when balanced in an open period (I1, I3), reversed, never changed (I2). */
class ManualEntryTest extends TestCase
{
    use InteractsWithLedger, RefreshDatabase, SeedsReference;

    public function test_a_balanced_entry_posts_with_the_next_journal_number_and_is_audited(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        $entry = $this->draft($this->accountant());
        $this->assertStringStartsWith('DRAFT-', $entry->number);
        $this->assertDatabaseHas('audit_logs', ['model_type' => JournalEntry::class, 'model_id' => $entry->id, 'action' => 'created']);

        $this->posting()->post($entry, $manager);

        $entry->refresh();
        $this->assertSame('posted', $entry->status);
        $this->assertSame('JE-2026-0001', $entry->number);
        $this->assertSame($manager->id, $entry->posted_by);
        $this->assertSame('250.0000', $this->rawBalance('6501'));
        $this->assertSame('-250.0000', $this->rawBalance('1101'));
    }

    public function test_an_unbalanced_entry_is_refused_politely(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager)->post(route('accounting.journal.store'), [
            'entry_date' => '2026-11-10',
            'lines' => [
                ['account_id' => $this->account('6101')->id, 'debit' => '500'],
                ['account_id' => $this->account('1101')->id, 'credit' => '450'],
            ],
        ])->assertSessionHas('success');
        $entry = JournalEntry::query()->latest('id')->firstOrFail();

        $this->actingAs($manager)->from(route('accounting.journal.show', $entry))
            ->post(route('accounting.journal.post', $entry))
            ->assertRedirect(route('accounting.journal.show', $entry))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '500.00') && str_contains($m, '450.00'));
        $this->assertSame('draft', $entry->fresh()->status);
    }

    public function test_an_entry_in_a_closed_period_is_refused_politely(): void
    {
        $manager = $this->manager();
        $entry = $this->draft($manager, date: '2026-10-15');
        AccountingPeriod::create(['name' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'closed_at' => now(), 'closed_by' => $manager->id]);

        $this->actingAs($manager)->from(route('accounting.journal.show', $entry))
            ->post(route('accounting.journal.post', $entry))
            ->assertRedirect(route('accounting.journal.show', $entry))
            ->assertSessionHas('error', fn ($m) => str_contains($m, '2026-10') && str_contains($m, 'closed'));
        $this->assertSame('draft', $entry->fresh()->status);

        // A new draft dated in the closed period is refused as well, with the same message.
        $this->actingAs($manager)->post(route('accounting.journal.store'), ['entry_date' => '2026-10-20', 'lines' => [
            ['account_id' => $this->account('6101')->id, 'debit' => '1'],
            ['account_id' => $this->account('1101')->id, 'credit' => '1'],
        ]])->assertSessionHas('error', fn ($m) => str_contains($m, 'closed'));
    }

    public function test_closing_a_period_with_drafts_in_it_is_refused(): void
    {
        $manager = $this->manager();
        $this->draft($manager, date: '2026-10-15');
        $period = AccountingPeriod::create(['name' => '2026-10', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);

        $this->actingAs($manager)->post(route('accounting.periods.close', $period))->assertSessionHas('error');
        $this->assertNull($period->fresh()->closed_at);
    }

    public function test_reversing_an_entry_zeroes_the_balances(): void
    {
        $manager = $this->manager();
        $this->actingAs($manager);
        $entry = $this->posting()->post($this->draft($manager), $manager);

        $reversal = $this->posting()->reverse($entry, $manager, '2026-11-12');

        $this->assertSame($entry->id, $reversal->reverses_entry_id);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame('0.0000', $this->rawBalance('6501'));
        $this->assertSame('0.0000', $this->rawBalance('1101'));
        $line = $reversal->lines()->where('account_id', $this->account('6501')->id)->first();
        $this->assertSame('250.0000', $line->credit);

        $this->expectException(AccountingException::class);
        $this->posting()->reverse($entry, $manager);
    }

    public function test_a_posted_entry_is_never_edited_or_deleted(): void
    {
        $manager = $this->manager();
        $entry = $this->posting()->post($this->draft($manager), $manager);

        $this->actingAs($manager)->get(route('accounting.journal.edit', $entry))->assertRedirect(route('accounting.journal.show', $entry));
        $this->actingAs($manager)->delete(route('accounting.journal.destroy', $entry))->assertSessionHas('error');
        $this->assertDatabaseHas('journal_entries', ['id' => $entry->id, 'status' => 'posted']);
    }

    public function test_a_draft_is_edited_and_deleted(): void
    {
        $accountant = $this->accountant();
        $entry = $this->draft($accountant);

        $this->actingAs($accountant)->put(route('accounting.journal.update', $entry), ['entry_date' => '2026-11-11', 'description' => 'Changed', 'lines' => [
            ['account_id' => $this->account('6502')->id, 'debit' => '75.5'],
            ['account_id' => $this->account('1101')->id, 'credit' => '75.5'],
        ]])->assertSessionHas('success');
        $entry->refresh();
        $this->assertSame('Changed', $entry->description);
        $this->assertSame(['6502', '1101'], $entry->lines()->with('account')->get()->pluck('account.code')->all());

        $this->actingAs($accountant)->delete(route('accounting.journal.destroy', $entry))->assertRedirect(route('accounting.journal.index'));
        $this->assertDatabaseMissing('journal_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('audit_logs', ['model_type' => JournalEntry::class, 'model_id' => $entry->id, 'action' => 'deleted']);
    }

    public function test_a_line_in_another_currency_takes_its_rate(): void
    {
        $manager = $this->manager();
        $entry = $this->posting()->createDraft(['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('6101')->id, 'currency' => 'CNY', 'debit' => '1000.05', 'rate' => '0.138765'],
            ['account_id' => $this->account('1201')->id, 'currency' => 'CNY', 'credit' => '1000.05', 'rate' => '0.138765'],
        ]], $manager);

        $line = $entry->lines()->first();
        $this->assertSame('CNY', $line->currency);
        $this->assertSame('1000.0500', $line->amount_currency);
        $this->assertSame('0.138765', $line->rate);
        $this->assertSame('138.7719', $line->debit); // 1000.05 × 0.138765 = 138.771938…

        $this->expectException(AccountingException::class);
        $this->posting()->createDraft(['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('6101')->id, 'currency' => 'CNY', 'debit' => '10'],
            ['account_id' => $this->account('1101')->id, 'credit' => '10'],
        ]], $manager);
    }

    public function test_an_account_limited_to_a_currency_refuses_other_currencies(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)->post(route('accounting.journal.store'), ['entry_date' => '2026-11-10', 'lines' => [
            ['account_id' => $this->account('1201')->id, 'currency' => 'USD', 'debit' => '10'],
            ['account_id' => $this->account('3101')->id, 'credit' => '10'],
        ]])->assertSessionHas('error', fn ($m) => str_contains($m, '1201') && str_contains($m, 'CNY'));
        $this->assertSame(0, JournalEntry::query()->count());
    }
}
