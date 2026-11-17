<?php

namespace Tests\Feature\Integration;

use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\User;
use App\Security\Roles;
use App\Services\Attachments\AttachmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SeedsReference;
use Tests\TestCase;

/**
 * A file follows its record's data rules, not only its screen (spec §6): a stamped supplier
 * contract stays hidden from sales, another salesperson's client's PI stays hidden, and a
 * payment's evidence is removed only by a manager.
 */
class AttachmentAccessTest extends TestCase
{
    use RefreshDatabase, SeedsReference;

    private function attach($record, User $by): int
    {
        return app(AttachmentService::class)->store($record, UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'), $by->id)->id;
    }

    public function test_supplier_order_files_stay_with_those_who_see_supplier_prices(): void
    {
        Storage::fake('local');
        $merch = User::factory()->create()->assignRole(Roles::MERCHANDISER);
        $sales = User::factory()->create()->assignRole(Roles::SALES);
        $id = $this->attach(SupplierOrder::factory()->create(), $merch);

        $this->actingAs($merch)->get(route('attachments.download', $id))->assertOk();
        $this->actingAs($sales)->get(route('attachments.download', $id))->assertForbidden();
    }

    public function test_a_pi_file_of_another_salespersons_client_is_hidden(): void
    {
        Storage::fake('local');
        $mine = User::factory()->create()->assignRole(Roles::SALES);
        $other = User::factory()->create()->assignRole(Roles::SALES);
        $pi = ProformaInvoice::factory()->create(['client_id' => Party::factory()->client()->create(['account_manager_id' => $mine->id])->id]);
        $id = $this->attach($pi, $mine);

        $this->actingAs($mine)->get(route('attachments.download', $id))->assertOk();
        $this->actingAs($other)->get(route('attachments.download', $id))->assertForbidden();
    }

    public function test_payment_evidence_is_removed_only_by_a_manager(): void
    {
        Storage::fake('local');
        $accountant = User::factory()->create()->assignRole(Roles::ACCOUNTANT);
        $manager = User::factory()->create()->assignRole(Roles::MANAGER);
        $payment = Payment::create([
            'number' => 'PAY-T-1', 'paid_on' => now()->toDateString(), 'kind' => 'client_receipt', 'direction' => 'in',
            'money_account_id' => MoneyAccount::factory()->create()->id, 'currency' => 'USD', 'amount' => 100, 'rate' => 1,
            'party_id' => Party::factory()->client()->create()->id, 'user_id' => $accountant->id,
        ]);
        $id = $this->attach($payment, $accountant);

        $this->actingAs($accountant)->get(route('attachments.download', $id))->assertOk();
        $this->actingAs($accountant)->delete(route('attachments.destroy', $id))->assertForbidden();
        $this->actingAs($manager)->delete(route('attachments.destroy', $id))->assertRedirect();
    }
}
