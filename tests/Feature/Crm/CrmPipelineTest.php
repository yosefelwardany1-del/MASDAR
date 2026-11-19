<?php

namespace Tests\Feature\Crm;

use App\Models\ClientStageChange;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Security\Roles;
use App\Services\Crm\CrmQueries;
use App\Services\Finance\PaymentService;
use App\Services\Parties\PartyService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use Database\Seeders\DemoUsersSeeder;
use Database\Seeders\MasdarDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * Spec §5.1: a client's stage is the latest of what a person set (lead, inquiry, dormant …) and
 * what its latest PI has reached in the documents (PI sent → delivered); the stages from "PI
 * sent" on are never typed.
 */
class CrmPipelineTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoUsersSeeder::class);
        $this->seed(MasdarDemoSeeder::class);
        $this->manager = User::where('email', 'manager@example.test')->sole();
    }

    private function stageOf(string $name): ?string
    {
        $id = Party::where('name', $name)->value('id');

        return app(CrmQueries::class)->stagesFor([$id])[$id]['stage'];
    }

    public function test_a_clients_stage_follows_the_documents_and_the_latest_signal_wins(): void
    {
        // Read from the documents: Gulf Home's goods are on board, Nile's PI is issued.
        $this->assertSame('shipped', $this->stageOf('Gulf Home Trading LLC'));
        $this->assertSame('pi_sent', $this->stageOf('Nile Kitchenware Co.'));
        // Set by a person.
        $this->assertSame('lead', $this->stageOf('Riyadh Home Center'));
        $this->assertSame('dormant', $this->stageOf('Casablanca Bazaar SARL'));

        // Confirming Nile's PI moves it on, by the documents.
        $this->travel(1)->minutes();
        $nile = ProformaInvoice::where('client_id', Party::where('name', 'Nile Kitchenware Co.')->value('id'))->where('is_current', true)->sole();
        app(PaymentService::class)->record(['kind' => 'client_receipt', 'paid_on' => now()->toDateString(),
            'money_account_id' => MoneyAccount::where('currency', $nile->currency)->value('id'),
            'amount' => app(ProformaInvoiceService::class)->depositStatus($nile)['required'], 'party_id' => $nile->client_id, 'proforma_invoice_id' => $nile->id], $this->manager);
        app(ProformaInvoiceService::class)->confirm($nile, $this->manager);
        $this->assertSame('confirmed', $this->stageOf('Nile Kitchenware Co.'));

        // A new inquiry from Gulf Home, set after its last shipment event, is the latest signal.
        $this->travel(1)->minutes();
        app(PartyService::class)->moveStage(Party::where('name', 'Gulf Home Trading LLC')->sole(), 'inquiry', $this->manager, 'Asked for a new catalogue');
        $this->assertSame('inquiry', $this->stageOf('Gulf Home Trading LLC'));
    }

    public function test_a_document_stage_is_never_typed_and_typed_stages_are_logged(): void
    {
        $client = Party::where('name', 'Riyadh Home Center')->sole();
        $parties = app(PartyService::class);

        $this->assertThrows(fn () => $parties->moveStage($client, 'shipped', $this->manager), ValidationException::class);
        $this->assertThrows(fn () => $parties->update($client, ['stage' => 'confirmed'], $this->manager), ValidationException::class);

        $parties->moveStage($client, 'inquiry', $this->manager, 'Sent the catalogue');
        $log = ClientStageChange::where('party_id', $client->id)->orderByDesc('id')->first();
        $this->assertSame(['lead', 'inquiry', 'Sent the catalogue'], [$log->from_stage, $log->to_stage, $log->note]);

        DB::beginTransaction();
        try {
            DB::table('client_stage_changes')->where('id', $log->id)->update(['to_stage' => 'lead']);
            $this->fail('the stage log is append-only');
        } catch (QueryException) {
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_the_crm_screens_follow_who_sees_what(): void
    {
        foreach (['crm.index', 'crm.pipeline', 'crm.activity', 'crm.tasks.index'] as $route) {
            $this->actingAs($this->manager)->get(route($route))->assertOk();
        }
        $this->actingAs($this->manager)->get(route('crm.activity'))->assertSee('Yiwu Bright Housewares Factory');

        // A salesperson limited to their own clients sees only theirs, and no supplier's contacts.
        $other = User::factory()->create()->assignRole(Roles::SALES);
        $this->actingAs($other)->get(route('crm.pipeline'))->assertOk()->assertDontSee('Gulf Home Trading LLC');
        $sales = User::where('email', 'sales@example.test')->sole();
        $this->actingAs($sales)->get(route('crm.pipeline'))->assertOk()->assertSee('Gulf Home Trading LLC');
        $this->actingAs($sales)->get(route('crm.activity'))->assertOk()->assertDontSee('Yiwu Bright Housewares Factory');

        // Someone else's client cannot be moved by a salesperson who does not manage it.
        $this->actingAs($other)->post(route('crm.pipeline.move'), ['party_id' => Party::where('name', 'Riyadh Home Center')->value('id'), 'stage' => 'dormant'])->assertNotFound();

        // No CRM without the screen.
        $qc = User::where('email', 'warehouse_qc@example.test')->sole();
        $this->actingAs($qc)->get(route('crm.index'))->assertForbidden();
    }

    public function test_quiet_clients_are_those_nobody_has_contacted_for_the_quiet_days(): void
    {
        $quiet = app(CrmQueries::class)->quiet($this->manager)->pluck('name')->all();
        // Basra's last contact was 45 days ago; Casablanca is dormant, so it is not chased.
        $this->assertSame(['Basra Trading House'], $quiet);
    }
}
