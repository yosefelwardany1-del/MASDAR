<?php

namespace App\Services\ServiceInvoices;

use App\Models\Lookup;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\ServiceInvoice;
use App\Models\ServiceInvoiceLine;
use App\Models\User;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use App\Support\Database\Refusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of service_invoices and service_invoice_lines (spec §5.8). A draft carries a
 * provisional number and changes freely; issuing (service_invoices.approve) numbers it from its
 * series and freezes it (triggers). It is posted from the accounting queue (Dr 1401 the client /
 * Cr each line's kind's income account) and voided only once that entry is reversed.
 */
class ServiceInvoiceService
{
    public function __construct(private NumberingService $numbers) {}

    public function createDraft(array $data, User $by): ServiceInvoice
    {
        $this->authorize($by, 'service_invoices.edit');
        $values = $this->header($data, $by);
        $lines = array_map(fn ($l) => $this->lineValues((array) $l), array_values($data['lines'] ?? []));

        return $this->write(function () use ($values, $lines, $by) {
            $invoice = ServiceInvoice::create($values + ['number' => 'DRAFT-'.Str::upper(Str::random(10)), 'status' => 'draft', 'created_by' => $by->id]);
            foreach ($lines as $i => $line) {
                ServiceInvoiceLine::create($line + ['service_invoice_id' => $invoice->id, 'line_no' => $i + 1]);
            }
            AuditTrail::record('created', $invoice, [], $values + ['lines' => $lines]);

            return $invoice;
        });
    }

    public function updateDraft(ServiceInvoice $invoice, array $data, User $by): ServiceInvoice
    {
        $this->authorize($by, 'service_invoices.edit');
        $values = $this->header($data, $by);

        return $this->write(function () use ($invoice, $values) {
            $invoice = $this->lockedDraft($invoice);
            $old = $invoice->only(array_keys($values));
            $invoice->update($values);
            AuditTrail::record('updated', $invoice, $old, $values);

            return $invoice;
        });
    }

    public function addLine(ServiceInvoice $invoice, array $data, User $by): ServiceInvoiceLine
    {
        $this->authorize($by, 'service_invoices.edit');
        $values = $this->lineValues($data);

        return $this->write(function () use ($invoice, $values) {
            $invoice = $this->lockedDraft($invoice);
            $line = ServiceInvoiceLine::create($values + ['service_invoice_id' => $invoice->id,
                'line_no' => (int) ServiceInvoiceLine::query()->where('service_invoice_id', $invoice->id)->max('line_no') + 1]);
            AuditTrail::record('line_added', $invoice, [], $values);

            return $line;
        });
    }

    public function deleteLine(ServiceInvoiceLine $line, User $by): void
    {
        $this->authorize($by, 'service_invoices.edit');
        $this->write(function () use ($line) {
            $invoice = $this->lockedDraft(ServiceInvoice::query()->findOrFail($line->service_invoice_id));
            $old = $line->only(['line_no', 'category', 'description', 'quantity', 'unit_price']);
            $line->delete();
            AuditTrail::record('line_removed', $invoice, $old, []);
        });
    }

    public function issue(ServiceInvoice $invoice, User $by, ?string $on = null, ?string $due = null): ServiceInvoice
    {
        $this->authorize($by, 'service_invoices.approve');
        $on = filled($on) ? $on : now()->toDateString();
        // B10: said before the database's CHECK would refuse it.
        if (filled($due) && $due < $on) {
            throw ValidationException::withMessages(['due_on' => __('The due date can\'t be before the invoice date.')]);
        }

        return $this->write(function () use ($invoice, $by, $on, $due) {
            $invoice = $this->lockedDraft($invoice);
            if (bccomp($invoice->total(), '0', 4) <= 0) {
                throw ValidationException::withMessages(['invoice' => __('Add at least one line with an amount before issuing the invoice.')]);
            }
            $number = $this->numbers->next('service_invoice', new \DateTimeImmutable($on));
            $invoice->update(['status' => 'issued', 'number' => $number, 'issued_on' => $on, 'due_on' => $due ?: null, 'issued_by' => $by->id, 'issued_at' => now()]);
            AuditTrail::record('issued', $invoice, ['status' => 'draft'], ['status' => 'issued', 'number' => $number, 'total' => $invoice->total()]);

            return $invoice;
        });
    }

    public function void(ServiceInvoice $invoice, string $reason, User $by): ServiceInvoice
    {
        $this->authorize($by, 'service_invoices.approve');
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['void_reason' => __('Say why the invoice is void.')]);
        }

        return $this->write(function () use ($invoice, $reason, $by) {
            $invoice = ServiceInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages(['invoice' => __('Only an issued invoice is voided; delete a draft instead.')]);
            }
            $posted = DB::table('journal_entries as e')->where('e.source_type', 'service_invoice')->where('e.source_id', $invoice->id)->whereNull('e.reverses_entry_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id'))->exists();
            if ($posted) {
                throw ValidationException::withMessages(['invoice' => __('This invoice is posted. Reverse its journal entry on the Accounting screen first.')]);
            }
            $invoice->update(['status' => 'void', 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => mb_substr(trim($reason), 0, 2000)]);
            AuditTrail::record('voided', $invoice, ['status' => 'issued'], ['status' => 'void', 'void_reason' => $reason]);

            return $invoice;
        });
    }

    public function deleteDraft(ServiceInvoice $invoice, User $by): void
    {
        $this->authorize($by, 'service_invoices.edit');
        $this->write(function () use ($invoice) {
            $invoice = $this->lockedDraft($invoice);
            $old = $invoice->only(['client_id', 'currency']);
            ServiceInvoiceLine::query()->where('service_invoice_id', $invoice->id)->delete();
            $invoice->delete();
            AuditTrail::record('deleted', $invoice, $old, []);
        });
    }

    private function header(array $data, User $by): array
    {
        $pi = filled($data['proforma_invoice_id'] ?? null) ? ProformaInvoice::query()->find((int) $data['proforma_invoice_id']) : null;
        $client = Party::query()->clients()->find($pi?->client_id ?? (int) ($data['client_id'] ?? 0));
        if (! $client || (Visibility::ownClientsOnly($by) && (int) $client->account_manager_id !== (int) $by->id)) {
            throw ValidationException::withMessages(['client_id' => __('Choose a client.')]);
        }
        $currency = strtoupper((string) ($data['currency'] ?? '') ?: ($pi?->currency ?? $client->currency ?? ''));
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            throw ValidationException::withMessages(['currency' => __('Choose a currency from the list of currencies.')]);
        }

        return ['client_id' => $client->id, 'proforma_invoice_id' => $pi?->id, 'currency' => $currency,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 2000) : null];
    }

    private function lineValues(array $data): array
    {
        $category = (string) ($data['category'] ?? '');
        if (! array_key_exists($category, Lookup::options('service_income'))) {
            throw ValidationException::withMessages(['category' => __('Choose what the charge is for.')]);
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Describe the line.')]);
        }

        return [
            'category' => $category, 'description' => mb_substr($description, 0, 2000),
            'quantity' => Money::amount(filled($data['quantity'] ?? null) ? $data['quantity'] : '1', 'quantity', true),
            'unit_price' => Money::amount($data['unit_price'] ?? null, 'unit_price', true),
        ];
    }

    private function lockedDraft(ServiceInvoice $invoice): ServiceInvoice
    {
        $invoice = ServiceInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
        if (! $invoice->isDraft()) {
            throw ValidationException::withMessages(['invoice' => __('Invoice :number is issued and never changes; void it and issue another.', ['number' => $invoice->number])]);
        }

        return $invoice;
    }

    private function authorize(User $by, string $permission): void
    {
        if (! $by->can($permission)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23001', 'P0001'], true)) {
                throw ValidationException::withMessages(['invoice' => Refusal::message($e)]);
            }
            throw $e;
        }
    }
}
