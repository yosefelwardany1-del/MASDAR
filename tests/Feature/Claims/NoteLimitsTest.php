<?php

namespace Tests\Feature\Claims;

use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\TradeNote;
use App\Models\User;
use App\Services\Claims\ClaimService;
use App\Services\Claims\TradeNoteService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** B04: credit notes stay within the PI, debit notes within the order, notes within the claim. */
class NoteLimitsTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private ProformaInvoice $pi;

    private string $total;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->manager->forceFill(['locale' => 'en'])->save();
        $this->pi = ProformaInvoice::query()->current()->whereHas('client', fn ($q) => $q->where('name', 'Gulf Home Trading LLC'))->sole();
        $this->total = (string) DB::table('pi_lines')->where('proforma_invoice_id', $this->pi->id)->sum('amount');
    }

    private function credit(string $amount, array $extra = []): TradeNote
    {
        return app(TradeNoteService::class)->createDraft('credit', $extra + ['proforma_invoice_id' => $this->pi->id, 'reason' => 'Short shipment',
            'lines' => [['description' => 'Allowance', 'unit_price' => $amount]]], $this->manager);
    }

    public function test_credit_notes_on_a_pi_stop_at_its_total_when_saved_and_when_issued(): void
    {
        $this->assertSame('10728.0000', bcadd($this->total, '0', 4));

        // Over the total: refused when saved, through the form too.
        $this->assertThrows(fn () => $this->credit(bcadd($this->total, '0.01', 2)), ValidationException::class,
            'Credit notes on '.$this->pi->reference().' would come to USD 10,728.01, more than its total of USD 10,728.00. At most USD 10,728.00 can still be credited.');
        $this->actingAs($this->manager)->post(route('trade-notes.store'), ['kind' => 'credit', 'proforma_invoice_id' => $this->pi->id, 'reason' => 'x',
            'line_description' => 'Allowance', 'line_amount' => '99999.00'])->assertSessionHasErrors('note');

        // Exactly the total is allowed. Two drafts that together pass it: the second is refused when issued.
        $all = $this->credit($this->total);
        $one = $this->credit('1');
        $service = app(TradeNoteService::class);
        $service->issue($all, $this->manager);
        $this->assertThrows(fn () => $service->issue($one->fresh(), $this->manager), ValidationException::class, 'At most USD 0.00 can still be credited.');
        $this->assertSame('draft', $one->fresh()->status);

        // The database refuses it too.
        DB::beginTransaction();
        try {
            DB::table('trade_notes')->where('id', $one->id)->update(['status' => 'issued', 'number' => 'CN-X', 'issued_on' => now()->toDateString(), 'issued_by' => $this->manager->id, 'issued_at' => now()]);
            $this->fail('the database issued a credit note past the PI total');
        } catch (QueryException $e) {
            $this->assertStringContainsString('would exceed its total', $e->getMessage());
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_note_settling_a_claim_stops_at_the_agreed_amount(): void
    {
        $claims = app(ClaimService::class);
        $claim = $claims->open(['kind' => 'defect', 'client_id' => $this->pi->client_id, 'proforma_invoice_id' => $this->pi->id,
            'opened_on' => now()->toDateString(), 'description' => 'Broken jars', 'claimed_amount' => '100', 'currency' => 'USD'], $this->manager);
        $claims->move($claim, 'agreed', $this->manager, null, '80');

        $this->assertThrows(fn () => $this->credit('80.01', ['claim_id' => $claim->id]), ValidationException::class,
            'Notes settling claim '.$claim->number.' would come to USD 80.01, more than the USD 80.00 agreed.');
        $note = $this->credit('80', ['claim_id' => $claim->id]);
        app(TradeNoteService::class)->issue($note, $this->manager);
        $this->assertSame('issued', $note->fresh()->status);
    }

    public function test_a_party_that_is_not_the_pis_client_is_an_error(): void
    {
        $other = Party::where('name', 'Nile Kitchenware Co.')->sole();
        $this->assertThrows(fn () => $this->credit('10', ['party_id' => $other->id]), ValidationException::class,
            'That PI is of another client. Choose the PI\'s client, or another PI.');
    }
}
