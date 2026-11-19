<?php

namespace Tests\Feature\Claims;

use App\Models\Claim;
use App\Models\Container;
use App\Models\JournalEntry;
use App\Models\MoneyAccount;
use App\Models\OrderStepDefinition;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\TradeNote;
use App\Models\User;
use App\Services\Accounting\PostingService;
use App\Services\Claims\ClaimService;
use App\Services\Claims\NoteQueries;
use App\Services\Claims\TradeNoteService;
use App\Services\Finance\PaymentService;
use App\Services\Shipping\ShippingQueries;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/** Spec §5.8: claims with factories and clients, settled by credit and debit notes posted to the books. */
class ClaimsAndNotesTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    private User $sales;

    private ProformaInvoice $pi;

    private SupplierOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
        $this->sales = User::where('email', 'sales@example.test')->sole();
        $this->pi = ProformaInvoice::query()->current()->whereHas('client', fn ($q) => $q->where('name', 'Gulf Home Trading LLC'))->sole();
        $this->order = SupplierOrder::query()->whereHas('supplier', fn ($q) => $q->where('name', 'Yiwu Bright Housewares Factory'))->sole();
    }

    private function assertRefused(callable $write): void
    {
        DB::beginTransaction();
        try {
            $write();
            $this->fail('the database accepted a write it must refuse');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_a_claim_is_opened_moved_and_its_facts_freeze_once_closed(): void
    {
        $claims = app(ClaimService::class);
        $claim = $claims->open([
            'kind' => 'defect', 'supplier_order_id' => $this->order->id, 'proforma_invoice_id' => $this->pi->id,
            'description' => '12 cartons of 500ml jars with chipped rims', 'cartons' => '12', 'claimed_amount' => '1116', 'currency' => 'CNY',
        ], $this->manager);

        $this->assertSame('CL-'.now()->year.'-0001', $claim->number);
        $this->assertSame([$this->order->supplier_id, $this->pi->client_id], [$claim->supplier_id, $claim->client_id], 'the parties come from the documents');
        $this->assertSame('open', $claim->fresh()->status());

        $claims->move($claim, 'with_factory', $this->manager, 'Photos sent to the factory');
        $claims->move($claim, 'agreed', $this->manager, 'Factory accepts 900', '900');
        $this->assertSame(['agreed', '900.0000'], [$claim->fresh()->status(), $claim->fresh()->latestEvent->agreed_amount]);
        $this->assertThrows(fn () => $claims->move($claim->fresh(), 'with_factory', $this->manager), ValidationException::class);

        $claims->move($claim->fresh(), 'closed', $this->manager);
        $this->assertThrows(fn () => $claims->update($claim->fresh(), ['kind' => 'shortage', 'description' => 'x', 'supplier_order_id' => $this->order->id], $this->manager), ValidationException::class);
        $this->assertRefused(fn () => DB::table('claims')->where('id', $claim->id)->update(['description' => 'changed']));
        $this->assertRefused(fn () => DB::table('claim_events')->where('claim_id', $claim->id)->delete());

        // Sales may not see suppliers: a claim against a factory is not theirs to open or see.
        $this->assertThrows(fn () => $claims->open(['kind' => 'defect', 'supplier_order_id' => $this->order->id, 'description' => 'x'], $this->sales), AuthorizationException::class);
        $this->actingAs($this->sales)->get(route('claims.show', $claim))->assertNotFound();
        $this->actingAs($this->manager)->get(route('claims.show', $claim))->assertOk()->assertSee($claim->number)->assertSee('Yiwu Bright Housewares Factory');
        $this->actingAs($this->manager)->get(route('claims.index', ['status' => 'all']))->assertOk()->assertSee($claim->number);
    }

    public function test_a_credit_note_lowers_what_the_client_owes_and_posts_to_the_books(): void
    {
        $notes = app(TradeNoteService::class);
        $note = $notes->createDraft('credit', ['proforma_invoice_id' => $this->pi->id, 'reason' => 'Allowance for chipped jars',
            'lines' => [['description' => 'Chipped rims, 12 cartons', 'unit_price' => '150']]], $this->sales);
        $this->assertSame(['credit', 'draft', 'USD', $this->pi->client_id], [$note->kind, $note->status, $note->currency, $note->party_id]);
        $notes->addLine($note, ['description' => 'Courier for replacement samples', 'quantity' => '2', 'unit_price' => '25'], $this->sales);
        $this->assertSame('200.0000', $note->total());

        // Issuing gives money back: a manager's.
        $this->assertThrows(fn () => $notes->issue($note, $this->sales), AuthorizationException::class);
        $note = $notes->issue($note, $this->manager);
        $this->assertSame('CN-'.now()->year.'-0001', $note->number);
        $this->assertRefused(fn () => DB::table('trade_notes')->where('id', $note->id)->update(['reason' => 'changed']));
        $this->assertRefused(fn () => DB::table('trade_note_lines')->where('trade_note_id', $note->id)->update(['unit_price' => 1]));

        // The PI: 10,728.00 total, 3,218.40 paid, 200 credited.
        $this->assertSame('200.0000', app(NoteQueries::class)->creditedForPiNumber($this->pi->pi_number, 'USD'));
        $line = $this->pi->lines()->first();
        $this->assertSame('200.0000', app(ShippingQueries::class)->piPaidStatus($line)['credited']);
        $this->actingAs($this->manager)->get(route('proforma-invoices.show', $this->pi))->assertOk()->assertSee('7,309.60');

        // Posted: Dr 4102 / Cr 1401 for the client; voiding needs the entry reversed.
        $posting = app(PostingService::class);
        $entry = $posting->postTradeNote($note, $this->manager);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame(['200.0000', '200.0000', $this->pi->client_id], [$lines['4102']->debit, $lines['1401']->credit, $lines['1401']->party_id]);
        $this->assertThrows(fn () => $notes->void($note->fresh(), 'Wrong amount', $this->manager), ValidationException::class);
        $this->assertRefused(fn () => DB::table('trade_notes')->where('id', $note->id)->update(['status' => 'void', 'voided_by' => $this->manager->id, 'voided_at' => now(), 'void_reason' => 'x']));
        $posting->reverse($entry, $this->manager);
        $notes->void($note->fresh(), 'Wrong amount', $this->manager);
        $this->assertSame('0.0000', app(NoteQueries::class)->creditedForPiNumber($this->pi->pi_number, 'USD'), 'a void note no longer counts');

        $this->actingAs($this->manager)->get(route('trade-notes.print', $note))->assertOk()->assertSee('CREDIT NOTE')->assertSee('VOID');
    }

    public function test_a_debit_note_on_the_factory_counts_towards_paying_the_order(): void
    {
        $notes = app(TradeNoteService::class);
        $orderTotal = (string) DB::table('supplier_order_lines')->where('supplier_order_id', $this->order->id)->sum('amount');
        $this->assertSame('16992.0000', $orderTotal);

        $note = $notes->createDraft('debit', ['supplier_order_id' => $this->order->id, 'reason' => 'Chipped jars, agreed with the factory',
            'lines' => [['description' => '12 cartons × 24 × 3.10', 'unit_price' => '892.80']]], $this->manager);
        $this->assertSame(['CNY', $this->order->supplier_id], [$note->currency, $note->party_id]);
        $note = $notes->issue($note, $this->manager);
        $this->assertSame('DN-'.now()->year.'-0001', $note->number);
        $this->assertSame('892.8000', app(NoteQueries::class)->debitedForOrder($this->order->id, 'CNY'));

        $entry = app(PostingService::class)->postTradeNote($note, $this->manager);
        $lines = $entry->lines()->with('account')->get()->keyBy(fn ($l) => $l->account->code);
        $this->assertSame($this->order->supplier_id, $lines['2201']->party_id);
        $this->assertSame('124.9920', $lines['5102']->credit, '892.80 CNY at 0.14');

        // Paying the rest (16,992 − 5,000 deposit − 892.80 debit note) completes every payment step.
        $payments = app(PaymentService::class);
        $rmb = MoneyAccount::where('name', 'Yiwu bank — RMB')->sole();
        ['out' => $out] = app(PaymentService::class)->transfer(['from_account_id' => MoneyAccount::where('name', 'HSBC Hong Kong — USD')->value('id'),
            'to_account_id' => $rmb->id, 'paid_on' => now()->toDateString(), 'amount' => '1600.00', 'in_amount' => '11099.20'], $this->manager);
        // The RMB has arrived once the transfer is approved; until then it cannot be paid on (B06).
        $payments->approve($out, $this->manager);
        $payment = $payments->record(['kind' => 'supplier_payment', 'paid_on' => now()->toDateString(), 'money_account_id' => $rmb->id, 'amount' => '11099.20',
            'party_id' => $this->order->supplier_id, 'supplier_order_id' => $this->order->id], $this->manager);
        $payments->approve($payment, $this->manager);
        $done = DB::table('supplier_order_step_events as e')->join('order_step_definitions as d', 'd.id', '=', 'e.step_definition_id')
            ->where('e.supplier_order_id', $this->order->id)->where('d.kind', 'payment')->where('e.status', 'done')->distinct()->count('d.code');
        $this->assertSame(OrderStepDefinition::where('kind', 'payment')->where('active', true)->count(), $done);
    }

    public function test_the_screens_respect_who_may_see_and_issue(): void
    {
        $note = app(TradeNoteService::class)->createDraft('debit', ['supplier_order_id' => $this->order->id, 'reason' => 'x',
            'lines' => [['description' => 'x', 'unit_price' => '10']]], $this->manager);
        $this->actingAs($this->sales)->get(route('trade-notes.index', ['kind' => 'debit']))->assertForbidden();
        $this->actingAs($this->sales)->get(route('trade-notes.show', $note))->assertNotFound();
        $this->actingAs($this->manager)->get(route('trade-notes.show', $note))->assertOk()->assertSee(__('Issue'));
        $this->actingAs($this->manager)->get(route('trade-notes.print', $note))->assertOk()->assertSee('DEBIT NOTE')->assertSee('扣款通知单')->assertDontSee('Gulf Home');
        $this->actingAs($this->manager)->post(route('trade-notes.issue', $note))->assertRedirect();
        $this->assertSame('issued', $note->fresh()->status);
        $this->actingAs($this->manager)->get(route('accounting.to-post.index'))->assertOk()->assertSee($note->fresh()->number);

        $this->actingAs($this->manager)->get(route('claims.create', ['order' => $this->order->id]))->assertOk();
        $this->actingAs($this->manager)->post(route('claims.store'), ['kind' => 'shortage', 'supplier_order_id' => $this->order->id, 'description' => 'Two cartons short'])
            ->assertRedirect();
        $this->assertSame(1, Claim::count());
        $this->assertSame(1, TradeNote::count());
        $this->assertTrue(Container::exists());
        $this->assertTrue(JournalEntry::exists());
        $this->assertTrue(Party::exists());
    }
}
