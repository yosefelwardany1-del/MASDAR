<?php

namespace App\Services\Claims;

use App\Models\Claim;
use App\Models\Party;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use App\Models\TradeNote;
use App\Models\TradeNoteLine;
use App\Models\User;
use App\Services\Accounting\Decimal;
use App\Services\Finance\PaymentService;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of trade_notes and trade_note_lines (spec §5.8). A credit note gives a client
 * money back on a PI (they owe less); a debit note claims money back from a factory on a
 * supplier order (MASDAR owes less). A draft is edited freely and carries a provisional number;
 * issuing (claims.approve) numbers it from its series, gapless, and freezes it (triggers). An
 * issued note is posted from the accounting queue, and voided only once that entry is reversed.
 *
 * A debit note on a supplier order lowers what is still to pay the factory, so issuing or
 * voiding one re-checks the order's payment steps (PaymentService::syncOrderPaymentSteps).
 */
class TradeNoteService
{
    public function __construct(private NumberingService $numbers, private PaymentService $payments) {}

    /**
     * @param  'credit'|'debit'  $kind
     * @param  array<string, mixed>  $data  party_id?, proforma_invoice_id? (credit), supplier_order_id? (debit), claim_id?, currency?, reason, lines?: list<array{description, quantity?, unit_price}>
     */
    public function createDraft(string $kind, array $data, User $by): TradeNote
    {
        $this->authorize($by, 'claims.edit');
        if (! in_array($kind, ['credit', 'debit'], true)) {
            throw new \InvalidArgumentException("Unknown note kind [{$kind}].");
        }
        $values = $this->header($kind, $data, $by);
        $lines = array_map(fn ($line) => $this->lineValues((array) $line), array_values($data['lines'] ?? []));

        return $this->write(function () use ($kind, $values, $lines, $by) {
            $note = TradeNote::create($values + ['kind' => $kind, 'number' => 'DRAFT-'.Str::upper(Str::random(10)), 'status' => 'draft', 'created_by' => $by->id]);
            foreach ($lines as $i => $line) {
                TradeNoteLine::create($line + ['trade_note_id' => $note->id, 'line_no' => $i + 1]);
            }
            $this->assertWithinLimits($note);
            AuditTrail::record('created', $note, [], $values + ['lines' => $lines]);

            return $note;
        });
    }

    public function updateDraft(TradeNote $note, array $data, User $by): TradeNote
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($note, $by);
        $values = $this->header($note->kind, $data, $by);

        return $this->write(function () use ($note, $values) {
            $note = $this->lockedDraft($note);
            $old = $note->only(array_keys($values));
            $note->update($values);
            $this->assertWithinLimits($note);
            AuditTrail::record('updated', $note, $old, $values);

            return $note;
        });
    }

    public function addLine(TradeNote $note, array $data, User $by): TradeNoteLine
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($note, $by);
        $values = $this->lineValues($data);

        return $this->write(function () use ($note, $values) {
            $note = $this->lockedDraft($note);
            $line = TradeNoteLine::create($values + [
                'trade_note_id' => $note->id,
                'line_no' => (int) TradeNoteLine::query()->where('trade_note_id', $note->id)->max('line_no') + 1,
            ]);
            $this->assertWithinLimits($note);
            AuditTrail::record('line_added', $note, [], $values);

            return $line;
        });
    }

    public function updateLine(TradeNoteLine $line, array $data, User $by): TradeNoteLine
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($line->note, $by);
        $values = $this->lineValues($data);

        return $this->write(function () use ($line, $values) {
            $note = $this->lockedDraft($line->note);
            $old = $line->only(array_keys($values));
            $line->update($values);
            $this->assertWithinLimits($note);
            AuditTrail::record('line_updated', $line->note, $old, $values);

            return $line;
        });
    }

    public function deleteLine(TradeNoteLine $line, User $by): void
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($line->note, $by);

        $this->write(function () use ($line) {
            $this->lockedDraft($line->note);
            $old = $line->only(['line_no', 'description', 'quantity', 'unit_price']);
            $line->delete();
            AuditTrail::record('line_removed', $line->note, $old, []);
        });
    }

    /** Issue the note: its number from its series, its date, and it never changes after. */
    public function issue(TradeNote $note, User $by, ?string $on = null): TradeNote
    {
        $this->authorize($by, 'claims.approve');
        $this->assertVisible($note, $by);
        $on = filled($on) ? (string) $on : now()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $on)) {
            throw ValidationException::withMessages(['issued_on' => __('Enter a valid date.')]);
        }

        $note = $this->write(function () use ($note, $by, $on) {
            $note = $this->lockedDraft($note);
            if (bccomp($note->total(), '0', 4) <= 0) {
                throw ValidationException::withMessages(['note' => __('Add at least one line with an amount before issuing the note.')]);
            }
            $this->assertWithinLimits($note);
            $number = $this->numbers->next($note->isCredit() ? 'credit_note' : 'debit_note', new \DateTimeImmutable($on));
            $note->update(['status' => 'issued', 'number' => $number, 'issued_on' => $on, 'issued_by' => $by->id, 'issued_at' => now()]);
            AuditTrail::record('issued', $note, ['status' => 'draft'], ['status' => 'issued', 'number' => $number, 'issued_on' => $on, 'total' => $note->total()]);

            return $note;
        });

        $this->syncOrder($note, $by);

        return $note;
    }

    /** Void an issued note; its journal entry must be reversed first (trigger). */
    public function void(TradeNote $note, string $reason, User $by): TradeNote
    {
        $this->authorize($by, 'claims.approve');
        $this->assertVisible($note, $by);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['void_reason' => __('Say why the note is void.')]);
        }

        $note = $this->write(function () use ($note, $reason, $by) {
            $note = TradeNote::query()->lockForUpdate()->findOrFail($note->id);
            if ($note->status !== 'issued') {
                throw ValidationException::withMessages(['note' => __('Only an issued note is voided; delete a draft instead.')]);
            }
            if (app(NoteQueries::class)->isPosted($note)) {
                throw ValidationException::withMessages(['note' => __('Note :number is posted: reverse its journal entry on the Accounting screen before voiding it.', ['number' => $note->number])]);
            }
            $note->update(['status' => 'void', 'voided_by' => $by->id, 'voided_at' => now(), 'void_reason' => mb_substr($reason, 0, 2000)]);
            AuditTrail::record('voided', $note, ['status' => 'issued'], ['status' => 'void', 'void_reason' => $reason]);

            return $note;
        });

        $this->syncOrder($note, $by, reopen: true);

        return $note;
    }

    public function deleteDraft(TradeNote $note, User $by): void
    {
        $this->authorize($by, 'claims.edit');
        $this->assertVisible($note, $by);
        if ($note->attachments()->exists()) {
            throw ValidationException::withMessages(['note' => __('The draft has attachments: remove them first.')]);
        }

        $this->write(function () use ($note) {
            $note = $this->lockedDraft($note);
            $old = $note->only(['kind', 'party_id', 'currency', 'reason']);
            TradeNoteLine::query()->where('trade_note_id', $note->id)->delete();
            $note->delete();
            AuditTrail::record('deleted', $note, $old, []);
        });
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function header(string $kind, array $data, User $by): array
    {
        $errors = [];
        $claim = filled($data['claim_id'] ?? null) ? Claim::query()->find((int) $data['claim_id']) : null;
        if (filled($data['claim_id'] ?? null) && ! $claim) {
            $errors['claim_id'] = __('That claim does not exist.');
        }

        $pi = null;
        $order = null;
        if ($kind === 'credit') {
            $pi = filled($data['proforma_invoice_id'] ?? null) ? ProformaInvoice::query()->find((int) $data['proforma_invoice_id']) : ($claim?->proformaInvoice);
            if (filled($data['proforma_invoice_id'] ?? null) && ! $pi) {
                $errors['proforma_invoice_id'] = __('That PI does not exist.');
            }
            if ($pi && filled($data['party_id'] ?? null) && (int) $data['party_id'] !== (int) $pi->client_id) {
                $errors['party_id'] = __('That PI is of another client. Choose the PI\'s client, or another PI.');
            }
            $partyId = $pi?->client_id ?? (filled($data['party_id'] ?? null) ? (int) $data['party_id'] : $claim?->client_id);
            $party = $partyId ? Party::query()->whereKey($partyId)->where('is_client', true)->first() : null;
            if (! $party) {
                $errors['party_id'] = __('Choose the client the credit note is for.');
            } elseif (Visibility::ownClientsOnly($by) && (int) $party->account_manager_id !== (int) $by->id) {
                throw new AuthorizationException(__('You may not do this.'));
            }
            if ($claim && $party && $claim->client_id !== null && $claim->client_id !== $party->id) {
                $errors['claim_id'] = __('That claim is with another client.');
            }
        } else {
            if (! Visibility::canSeeSupplierPrices($by)) {
                throw new AuthorizationException(__('You may not see suppliers, so you cannot raise a debit note.'));
            }
            $order = filled($data['supplier_order_id'] ?? null) ? SupplierOrder::query()->find((int) $data['supplier_order_id']) : ($claim?->supplierOrder);
            if (filled($data['supplier_order_id'] ?? null) && ! $order) {
                $errors['supplier_order_id'] = __('That purchase order does not exist.');
            }
            if ($order && filled($data['party_id'] ?? null) && (int) $data['party_id'] !== (int) $order->supplier_id) {
                $errors['party_id'] = __('That purchase order is with another supplier. Choose its supplier, or another order.');
            }
            $partyId = $order?->supplier_id ?? (filled($data['party_id'] ?? null) ? (int) $data['party_id'] : $claim?->supplier_id);
            $party = $partyId ? Party::query()->whereKey($partyId)->where('is_supplier', true)->first() : null;
            if (! $party) {
                $errors['party_id'] = __('Choose the supplier the debit note is raised on.');
            }
            if ($claim && $party && $claim->supplier_id !== null && $claim->supplier_id !== $party->id) {
                $errors['claim_id'] = __('That claim is against another supplier.');
            }
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            $errors['reason'] = __('Say what the note is for.');
        }
        $document = $pi ?? $order;
        $currency = strtoupper((string) ($document?->currency ?? ($data['currency'] ?? '') ?: ($party?->currency ?? '')));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = __('Choose the currency of the note.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'party_id' => $party->id,
            'proforma_invoice_id' => $pi?->id,
            'supplier_order_id' => $order?->id,
            'claim_id' => $claim?->id,
            'currency' => $currency,
            'reason' => mb_substr($reason, 0, 2000),
        ];
    }

    /** @return array{description: string, quantity: string, unit_price: string} */
    private function lineValues(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Describe the line.')]);
        }

        return [
            'description' => mb_substr($description, 0, 2000),
            'quantity' => Money::amount(filled($data['quantity'] ?? null) ? $data['quantity'] : '1', 'quantity', true),
            'unit_price' => Money::amount($data['unit_price'] ?? null, 'unit_price', true),
        ];
    }

    /**
     * B04: notes stay within what they settle. Credit notes on a PI (issued ones and this draft)
     * never come to more than the PI's total; debit notes on a purchase order never more than the
     * order's; notes settling a claim never more than its agreed amount (or the claimed amount
     * before one is agreed). The trigger holds the same rule when a note is issued.
     */
    private function assertWithinLimits(TradeNote $note): void
    {
        $total = $note->total();
        $issued = fn ($query) => (string) $query->join('trade_note_lines as l', 'l.trade_note_id', '=', 'n.id')
            ->where('n.status', 'issued')->where('n.id', '<>', $note->id)->where('n.currency', $note->currency)
            ->selectRaw('COALESCE(SUM(l.amount), 0)::numeric(18,4) AS t')->value('t');
        $money = fn (string $amount) => $note->currency.' '.Decimal::format($amount);

        if ($note->isCredit() && $note->proforma_invoice_id !== null) {
            $pi = ProformaInvoice::query()->findOrFail($note->proforma_invoice_id);
            $current = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->where('is_current', true)->first() ?? $pi;
            $cap = (string) DB::table('pi_lines')->where('proforma_invoice_id', $current->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
            $others = $issued(DB::table('trade_notes as n')->join('proforma_invoices as r', 'r.id', '=', 'n.proforma_invoice_id')
                ->where('n.kind', 'credit')->where('r.pi_number', $pi->pi_number));
            if (bccomp(bcadd($others, $total, 4), $cap, 4) > 0) {
                throw ValidationException::withMessages(['note' => __('Credit notes on :document would come to :total, more than its total of :cap. At most :left can still be credited.', [
                    'document' => $current->reference(), 'total' => $money(bcadd($others, $total, 4)), 'cap' => $money($cap), 'left' => $money(Money::max('0', bcsub($cap, $others, 4))),
                ])]);
            }
        }
        if (! $note->isCredit() && $note->supplier_order_id !== null) {
            $order = SupplierOrder::query()->findOrFail($note->supplier_order_id);
            $cap = (string) DB::table('supplier_order_lines')->where('supplier_order_id', $order->id)->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,4) AS t')->value('t');
            $others = $issued(DB::table('trade_notes as n')->where('n.kind', 'debit')->where('n.supplier_order_id', $order->id));
            if (bccomp(bcadd($others, $total, 4), $cap, 4) > 0) {
                throw ValidationException::withMessages(['note' => __('Debit notes on :document would come to :total, more than its total of :cap. At most :left can still be debited.', [
                    'document' => $order->number, 'total' => $money(bcadd($others, $total, 4)), 'cap' => $money($cap), 'left' => $money(Money::max('0', bcsub($cap, $others, 4))),
                ])]);
            }
        }
        if ($note->claim_id !== null && ($claim = Claim::query()->find($note->claim_id))) {
            $cap = $claim->agreedAmount() ?? ($claim->claimed_amount !== null ? (string) $claim->claimed_amount : null);
            if ($cap !== null && ($claim->currency === null || $claim->currency === $note->currency)) {
                $others = $issued(DB::table('trade_notes as n')->where('n.claim_id', $claim->id));
                if (bccomp(bcadd($others, $total, 4), $cap, 4) > 0) {
                    throw ValidationException::withMessages(['note' => __('Notes settling claim :claim would come to :total, more than the :cap agreed. At most :left is left to settle.', [
                        'claim' => $claim->number, 'total' => $money(bcadd($others, $total, 4)), 'cap' => $money($cap), 'left' => $money(Money::max('0', bcsub($cap, $others, 4))),
                    ])]);
                }
            }
        }
    }

    private function syncOrder(TradeNote $note, User $by, bool $reopen = false): void
    {
        if (! $note->isCredit() && $note->supplier_order_id !== null && ($order = SupplierOrder::query()->find($note->supplier_order_id))) {
            $this->payments->syncOrderPaymentSteps($order, $by, $reopen, 'Debit note '.$note->number);
        }
    }

    private function lockedDraft(TradeNote $note): TradeNote
    {
        $note = TradeNote::query()->lockForUpdate()->findOrFail($note->id);
        if (! $note->isDraft()) {
            throw ValidationException::withMessages(['note' => __('Note :number is issued and never changes; void it and issue another.', ['number' => $note->number])]);
        }

        return $note;
    }

    private function assertVisible(?TradeNote $note, User $by): void
    {
        if ($note === null || ! $note->visibleTo($by)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
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
                throw ValidationException::withMessages(['note' => __('The database refused this change: :reason', ['reason' => ClaimService::reason($e)])]);
            }
            throw $e;
        }
    }
}
