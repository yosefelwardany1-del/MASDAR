<?php

namespace App\Services\Samples;

use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\Sample;
use App\Models\SampleEvent;
use App\Models\User;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The only writer of samples and sample_events (spec §5.8). */
class SampleService
{
    public function __construct(private NumberingService $numbers) {}

    public function create(array $data, User $by): Sample
    {
        $this->authorize($by);
        $values = $this->values($data, $by);

        return DB::transaction(function () use ($values, $by) {
            $sample = Sample::create($values + ['number' => $this->numbers->next('sample'), 'created_by' => $by->id]);
            SampleEvent::create(['sample_id' => $sample->id, 'status' => 'requested', 'happened_on' => $values['requested_on'], 'user_id' => $by->id]);
            AuditTrail::record('created', $sample, [], $values);

            return $sample;
        });
    }

    /** A new step: at the factory, received, sent (with courier and tracking), approved or rejected by the client, cancelled. */
    public function record(Sample $sample, array $data, User $by): SampleEvent
    {
        $this->authorize($by);
        $status = (string) ($data['status'] ?? '');
        if (! in_array($status, Sample::STATUSES, true) || $status === 'requested') {
            throw ValidationException::withMessages(['status' => __('Choose what happened.')]);
        }
        $on = filled($data['happened_on'] ?? null) ? (string) $data['happened_on'] : now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            throw ValidationException::withMessages(['happened_on' => __('Enter a valid date.')]);
        }
        if ($status === 'sent' && blank($data['courier'] ?? null)) {
            throw ValidationException::withMessages(['courier' => __('Name the courier the sample went with.')]);
        }

        return DB::transaction(function () use ($sample, $status, $on, $data, $by) {
            $event = SampleEvent::create([
                'sample_id' => $sample->id, 'status' => $status, 'happened_on' => $on,
                'courier' => filled($data['courier'] ?? null) ? mb_substr(trim((string) $data['courier']), 0, 80) : null,
                'tracking_no' => filled($data['tracking_no'] ?? null) ? mb_substr(trim((string) $data['tracking_no']), 0, 80) : null,
                'note' => filled($data['note'] ?? null) ? mb_substr(trim((string) $data['note']), 0, 2000) : null,
                'user_id' => $by->id,
            ]);
            AuditTrail::record('status_changed', $sample, [], ['status' => $status, 'on' => $on, 'tracking_no' => $event->tracking_no]);

            return $event;
        });
    }

    private function values(array $data, User $by): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Describe the sample.')]);
        }
        $pi = filled($data['proforma_invoice_id'] ?? null) ? ProformaInvoice::query()->find((int) $data['proforma_invoice_id']) : null;
        $clientId = $pi?->client_id ?? (filled($data['client_id'] ?? null) ? (int) $data['client_id'] : null);
        if ($clientId !== null) {
            $client = Party::query()->clients()->find($clientId);
            if (! $client || (Visibility::ownClientsOnly($by) && (int) $client->account_manager_id !== (int) $by->id)) {
                throw ValidationException::withMessages(['client_id' => __('Choose a client.')]);
            }
        }
        $supplierId = filled($data['supplier_id'] ?? null) && Visibility::canSeeSupplierPrices($by) ? (int) $data['supplier_id'] : null;
        if ($supplierId !== null && ! Party::query()->suppliers()->whereKey($supplierId)->exists()) {
            throw ValidationException::withMessages(['supplier_id' => __('Choose a supplier.')]);
        }

        return [
            'description' => mb_substr($description, 0, 2000),
            'client_id' => $clientId, 'supplier_id' => $supplierId, 'proforma_invoice_id' => $pi?->id,
            'product_id' => filled($data['product_id'] ?? null) ? (int) $data['product_id'] : null,
            'supplier_order_id' => filled($data['supplier_order_id'] ?? null) && Visibility::canSeeSupplierPrices($by) ? (int) $data['supplier_order_id'] : null,
            'quantity' => filled($data['quantity'] ?? null) ? Money::amount($data['quantity'], 'quantity', true) : null,
            'requested_on' => filled($data['requested_on'] ?? null) ? (string) $data['requested_on'] : now()->toDateString(),
            'needed_by' => filled($data['needed_by'] ?? null) ? (string) $data['needed_by'] : null,
        ];
    }

    private function authorize(User $by): void
    {
        if (! $by->can('samples.edit')) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }
}
