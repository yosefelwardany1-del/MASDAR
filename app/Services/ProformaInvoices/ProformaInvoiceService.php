<?php

namespace App\Services\ProformaInvoices;

use App\Models\BusinessSetting;
use App\Models\Party;
use App\Models\PiLine;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\User;
use App\Services\Numbering\NumberingService;
use App\Services\Products\ProductService;
use App\Support\Audit\AuditTrail;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of proforma_invoices and pi_lines (spec §5.2).
 *
 * Draft → issued (immutable, I4) → accepted → confirmed (the client's deposit arrived); any of
 * them → cancelled with a reason. A change after issue is a new revision: a draft row with the
 * same pi_number and the next revision number, not current until it is issued, when the
 * revision it replaces is superseded in the same transaction.
 *
 * The PHP checks here only give the friendly message; the triggers of migration
 * 2026_11_17_000003 are the gate, and whatever they refuse comes back as a validation error
 * (gate()), never a 500. Generated columns (total_qty, amount, total_cbm, total_kg) are never
 * written. Every action is audited inside its own transaction.
 */
class ProformaInvoiceService
{
    /** Header fields a draft may change. */
    private const HEADER = ['client_id', 'shipped_to', 'po_number', 'po_date', 'currency', 'deposit_percent', 'notes'];

    /** Line fields the screen writes (never the generated totals). */
    private const LINE = ['description', 'unit', 'cartons', 'qty_per_carton', 'unit_price', 'cbm_per_carton', 'kg_per_carton',
        'supplier_id', 'supplier_price', 'supplier_currency'];

    /** Statuses a new revision may be made from, and that a newer issued revision supersedes. */
    public const REVISABLE = ['issued', 'accepted', 'confirmed'];

    public function __construct(private NumberingService $numbers, private ProductService $products) {}

    /**
     * A new draft, numbered from the "pi" series, revision 1 and current. Currency defaults to
     * the business PI currency; the deposit to the client's, else the business default.
     *
     * @param  array<string, mixed>  $data  validated header fields
     */
    public function createDraft(array $data, User $by): ProformaInvoice
    {
        return $this->gate(fn () => DB::transaction(function () use ($data, $by) {
            $client = $this->client($data['client_id'] ?? null);
            $settings = BusinessSetting::current();
            $deposit = $this->deposit($data['deposit_percent'] ?? null, $client);

            $pi = ProformaInvoice::create([
                'pi_number' => $this->numbers->next('pi'),
                'revision' => 1,
                'is_current' => true,
                'status' => 'draft',
                'client_id' => $client->id,
                'shipped_to' => filled($data['shipped_to'] ?? null) ? $data['shipped_to'] : $client->default_destination,
                'po_number' => $data['po_number'] ?? null,
                'po_date' => $data['po_date'] ?? null,
                'currency' => filled($data['currency'] ?? null) ? strtoupper($data['currency']) : $settings->pi_currency,
                'deposit_percent' => $deposit,
                'terms_text' => $settings->piTerms($deposit),
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
            ]);
            AuditTrail::record('created', $pi, [], $this->headerValues($pi) + ['pi_number' => $pi->pi_number, 'revision' => 1]);

            return $pi;
        }));
    }

    /**
     * Change a draft's header. A deposit left empty falls back to the client's, else the
     * business default; the terms follow the deposit.
     *
     * @param  array<string, mixed>  $data  validated header fields
     */
    public function updateDraft(ProformaInvoice $pi, array $data, User $by): ProformaInvoice
    {
        return $this->gate(fn () => DB::transaction(function () use ($pi, $data) {
            $pi = $this->lock($pi);
            $this->assertDraft($pi);

            $data = Arr::only($data, self::HEADER);
            $client = array_key_exists('client_id', $data) ? $this->client($data['client_id']) : $pi->client;
            if (array_key_exists('deposit_percent', $data)) {
                $data['deposit_percent'] = $this->deposit($data['deposit_percent'], $client);
            }
            if (array_key_exists('shipped_to', $data) && blank($data['shipped_to'])) {
                $data['shipped_to'] = $client->default_destination;
            }
            if (array_key_exists('currency', $data)) {
                $data['currency'] = filled($data['currency']) ? strtoupper($data['currency']) : BusinessSetting::current()->pi_currency;
            }
            $data['terms_text'] = BusinessSetting::current()->piTerms($data['deposit_percent'] ?? $pi->deposit_percent);

            $old = $this->headerValues($pi);
            $pi->update($data);
            AuditTrail::record('updated', $pi, $old, $this->headerValues($pi));

            return $pi;
        }));
    }

    /**
     * Add a line to a draft. With `product_id` the line picks that product and takes its
     * description, unit, quantity per carton, CBM and KG per carton (and usual supplier) for
     * whatever it leaves empty; without, a product is created on the spot from the line.
     *
     * @param  array<string, mixed>  $data  validated line fields, plus product_id or none
     */
    public function addLine(ProformaInvoice $pi, array $data, User $by, ?UploadedFile $photo = null): PiLine
    {
        return $this->gate(fn () => DB::transaction(function () use ($pi, $data, $by, $photo) {
            $pi = $this->lock($pi);
            $this->assertDraft($pi);

            $product = filled($data['product_id'] ?? null)
                ? Product::query()->findOrFail($data['product_id'])
                : $this->products->create($this->productFromLine($data, $pi), $by, $photo);

            $line = PiLine::create($this->lineValues(Arr::only($data, self::LINE), $product) + [
                'proforma_invoice_id' => $pi->id,
                'line_no' => (int) PiLine::query()->where('proforma_invoice_id', $pi->id)->max('line_no') + 1,
                'product_id' => $product->id,
            ])->refresh();
            AuditTrail::record('line_added', $pi, [], $this->lineAudit($line) + ['new_product' => blank($data['product_id'] ?? null)]);

            return $line;
        }));
    }

    /**
     * Change a draft's line. Only the keys present change: a screen that hides supplier
     * fields leaves them out, and the line keeps its supplier.
     *
     * @param  array<string, mixed>  $data  validated line fields
     */
    public function updateLine(PiLine $line, array $data, User $by): PiLine
    {
        return $this->gate(fn () => DB::transaction(function () use ($line, $data) {
            $pi = $this->lock($line->proformaInvoice);
            $this->assertDraft($pi);
            $line = PiLine::query()->whereKey($line->id)->lockForUpdate()->firstOrFail();

            $data = Arr::only($data, self::LINE);
            foreach (['unit', 'cartons', 'qty_per_carton', 'unit_price'] as $required) {
                if (array_key_exists($required, $data) && blank($data[$required])) {
                    throw ValidationException::withMessages([$required => __('This field is required.')]);
                }
            }
            foreach (['cbm_per_carton', 'kg_per_carton'] as $measure) {
                if (array_key_exists($measure, $data) && blank($data[$measure])) {
                    $data[$measure] = '0';
                }
            }
            $data = $this->withSupplierCurrency($data, $line->supplier_currency);

            $old = $this->lineAudit($line);
            $line->update($data);
            $line->refresh();
            AuditTrail::record('line_updated', $pi, $old, $this->lineAudit($line));

            return $line;
        }));
    }

    /** Remove a draft's line and renumber the others 1, 2, 3 … in their order. */
    public function removeLine(PiLine $line, User $by): void
    {
        $this->gate(fn () => DB::transaction(function () use ($line) {
            $pi = $this->lock($line->proformaInvoice);
            $this->assertDraft($pi);

            $old = $this->lineAudit($line);
            PiLine::query()->whereKey($line->id)->delete();
            // Two steps so the unique (proforma_invoice_id, line_no) never sees two equal numbers.
            DB::update('UPDATE pi_lines SET line_no = line_no + 1000000 WHERE proforma_invoice_id = ?', [$pi->id]);
            DB::update('UPDATE pi_lines l SET line_no = r.n FROM (
                    SELECT id, row_number() OVER (ORDER BY line_no, id) AS n FROM pi_lines WHERE proforma_invoice_id = ?
                ) r WHERE l.id = r.id', [$pi->id]);
            AuditTrail::record('line_removed', $pi, $old, []);
        }));
    }

    /** Whether $user may issue a PI: approve when the business option asks for it, else edit. */
    public function mayIssue(User $user): bool
    {
        return $user->can(BusinessSetting::current()->pi_issue_requires_approval ? 'proforma_invoices.approve' : 'proforma_invoices.edit');
    }

    /**
     * Issue a draft with at least one line. A later revision becomes the current one: the
     * revision it replaces stops being current first (the partial unique index allows one
     * current row per number) and, if it was issued, accepted or confirmed, is superseded.
     */
    public function issue(ProformaInvoice $pi, User $by): ProformaInvoice
    {
        if (! $this->mayIssue($by)) {
            throw new AuthorizationException(__('Issuing a PI needs a manager\'s approval.'));
        }

        return $this->gate(fn () => DB::transaction(function () use ($pi, $by) {
            $pi = $this->lock($pi);
            if (! $pi->isDraft()) {
                throw ValidationException::withMessages(['pi' => __('Only a draft can be issued.')]);
            }
            if (! PiLine::query()->where('proforma_invoice_id', $pi->id)->exists()) {
                throw ValidationException::withMessages(['pi' => __('Add at least one line before issuing the PI.')]);
            }

            $previous = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->where('is_current', true)
                ->whereKeyNot($pi->id)->lockForUpdate()->first();
            if ($previous) {
                $changes = ['is_current' => false] + (in_array($previous->status, self::REVISABLE, true) ? ['status' => 'superseded'] : []);
                $old = $previous->only(array_keys($changes));
                $previous->update($changes);
                AuditTrail::record('superseded', $previous, $old, $changes + ['by_revision' => $pi->revision]);
            }

            $pi->update(['status' => 'issued', 'issued_at' => now(), 'issued_by' => $by->id, 'is_current' => true]);
            AuditTrail::record('issued', $pi, ['status' => 'draft'], ['status' => 'issued', 'revision' => $pi->revision, 'issued_by' => $by->id]);

            return $pi;
        }));
    }

    /** The client accepted the issued PI. */
    public function accept(ProformaInvoice $pi, User $by): ProformaInvoice
    {
        return $this->move($pi, ['issued'], 'accepted', ['accepted_at' => now()], __('Only an issued PI can be marked accepted.'));
    }

    /** The client's deposit has arrived (informational: payments are their own records). */
    public function confirm(ProformaInvoice $pi, User $by): ProformaInvoice
    {
        return $this->move($pi, ['issued', 'accepted'], 'confirmed', ['confirmed_at' => now()], __('Only an issued or accepted PI can be confirmed.'));
    }

    /**
     * Cancel a PI (a draft, or an issued, accepted or confirmed one) with the reason (B02). A PI that
     * money or the books still rely on is not cancelled: its client receipts are refunded or moved
     * to the client's balance, its posted invoice reversed and its purchase orders cancelled first.
     * A confirmed PI — the sales order — is cancelled by a manager (the approve level).
     */
    public function cancel(ProformaInvoice $pi, User $by, string $reason): ProformaInvoice
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['cancel_reason' => __('Give the reason for cancelling.')]);
        }

        return $this->move($pi, ['draft', ...self::REVISABLE], 'cancelled', ['cancelled_at' => now(), 'cancel_reason' => $reason],
            __('This PI is already cancelled or superseded.'), function (ProformaInvoice $pi) use ($by) {
                if ($pi->status === 'confirmed' && ! $by->can('proforma_invoices.approve')) {
                    throw ValidationException::withMessages(['pi' => __('A confirmed PI is the sales order: a manager cancels it.')]);
                }
                // A draft — a first draft, or a revision being prepared — is dropped freely: money and
                // invoices belong to the PI number, which lives on in its current revision.
                if ($pi->status !== 'draft' && ($why = $this->whyNotCancellable($pi))) {
                    throw ValidationException::withMessages(['pi' => $why]);
                }
            });
    }

    /** Null when $pi may be cancelled; otherwise what has to be done first (B02; the trigger holds the same rule). */
    public function whyNotCancellable(ProformaInvoice $pi): ?string
    {
        $revisions = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->select('id');

        $invoice = DB::table('journal_entries as e')
            ->where('e.source_type', 'proforma_invoice')->where('e.event', 'client_invoice')->whereIn('e.source_id', $revisions)
            ->whereNull('e.reverses_entry_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id'))
            ->value('e.number');
        if ($invoice) {
            return __('This PI\'s invoice :entry is posted. Reverse it on the Accounting screen first.', ['entry' => $invoice]);
        }

        $received = DB::table('payments')->whereIn('proforma_invoice_id', $revisions)->where('kind', 'client_receipt')
            ->groupBy('currency')->havingRaw('SUM(signed_amount) <> 0')
            ->selectRaw('currency, SUM(signed_amount)::numeric(18,2) AS amount')->get();
        if ($received->isNotEmpty()) {
            return __('Money has been received on this PI (:amount). Refund it to the client, or reverse the receipt and record it again on the client without a PI, first.', [
                'amount' => $received->map(fn ($r) => $r->currency.' '.number_format((float) $r->amount, 2))->implode(', '),
            ]);
        }

        $orders = DB::table('supplier_orders as so')->where('so.status', '<>', 'cancelled')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('supplier_order_lines as sol')->join('pi_lines as pl', 'pl.id', '=', 'sol.pi_line_id')
                ->whereColumn('sol.supplier_order_id', 'so.id')->whereNull('sol.released_at')->whereIn('pl.proforma_invoice_id', $revisions))
            ->orderBy('so.number')->pluck('so.number');
        if ($orders->isNotEmpty()) {
            return __('This PI has purchase orders (:orders). Cancel them first.', ['orders' => $orders->implode(', ')]);
        }

        return null;
    }

    /**
     * A new draft revision of the current issued, accepted or confirmed PI: same number,
     * revision + 1, not current until issued, with its lines copied (source_line_id).
     */
    public function createRevision(ProformaInvoice $pi, User $by): ProformaInvoice
    {
        return $this->gate(fn () => DB::transaction(function () use ($pi, $by) {
            $pi = $this->lock($pi);
            if (! $pi->is_current || ! in_array($pi->status, self::REVISABLE, true)) {
                throw ValidationException::withMessages(['pi' => __('A new revision is made from the current issued, accepted or confirmed PI.')]);
            }
            $pending = ProformaInvoice::query()->where('pi_number', $pi->pi_number)->where('status', 'draft')->first();
            if ($pending) {
                throw ValidationException::withMessages(['pi' => __('A draft revision of this PI already exists: :reference.', ['reference' => $pending->reference()])]);
            }

            $revision = ProformaInvoice::create(Arr::only($pi->getAttributes(), [
                'pi_number', 'client_id', 'shipped_to', 'po_number', 'po_date', 'currency', 'deposit_percent', 'terms_text', 'notes',
            ]) + [
                'revision' => (int) ProformaInvoice::query()->where('pi_number', $pi->pi_number)->max('revision') + 1,
                'is_current' => false,
                'status' => 'draft',
                'created_by' => $by->id,
            ]);
            foreach (PiLine::query()->where('proforma_invoice_id', $pi->id)->orderBy('line_no')->get() as $line) {
                PiLine::create(Arr::only($line->getAttributes(), ['line_no', 'product_id', ...self::LINE]) + [
                    'proforma_invoice_id' => $revision->id,
                    'source_line_id' => $line->id,
                ]);
            }
            AuditTrail::record('revision_created', $revision, [], [
                'pi_number' => $revision->pi_number, 'revision' => $revision->revision, 'from_revision' => $pi->revision,
            ]);

            return $revision;
        }));
    }

    /** One status move, audited. */
    private function move(ProformaInvoice $pi, array $from, string $to, array $stamps, string $refusal, ?callable $guard = null): ProformaInvoice
    {
        return $this->gate(fn () => DB::transaction(function () use ($pi, $from, $to, $stamps, $refusal, $guard) {
            $pi = $this->lock($pi);
            if (! in_array($pi->status, $from, true)) {
                throw ValidationException::withMessages(['pi' => $refusal]);
            }
            if ($guard) {
                $guard($pi);
            }
            $old = ['status' => $pi->status];
            $pi->update(['status' => $to] + $stamps);
            AuditTrail::record($to, $pi, $old, ['status' => $to] + array_diff_key($stamps, array_flip(['accepted_at', 'confirmed_at', 'cancelled_at'])));

            return $pi;
        }));
    }

    /** Run a write; a refusal from the database (a trigger or a CHECK) becomes a validation error. */
    private function gate(callable $write): mixed
    {
        try {
            return $write();
        } catch (QueryException $e) {
            $message = match ($e->errorInfo[0] ?? (string) $e->getCode()) {
                // restrict_violation: the triggers' "an issued PI never changes".
                '23001' => __('This PI has been issued and can no longer change. Make a new revision to change it.'),
                '23514' => match (true) {
                    str_contains($e->getMessage(), 'pi_lines_quantities_check') => __('Cartons and quantity per carton must be more than zero; prices, CBM and KG cannot be negative.'),
                    str_contains($e->getMessage(), 'deposit_check') => __('The deposit must be between 0 and 100%.'),
                    default => __('A PI in this status cannot make that move.'),
                },
                '23505' => __('Someone else changed this PI at the same moment. Reload the page and try again.'),
                '23503' => __('This change refers to a record that is missing or still in use.'),
                default => throw $e,
            };

            throw ValidationException::withMessages(['pi' => $message]);
        }
    }

    private function lock(ProformaInvoice $pi): ProformaInvoice
    {
        return ProformaInvoice::query()->whereKey($pi->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertDraft(ProformaInvoice $pi): void
    {
        $message = match ($pi->status) {
            'draft' => null,
            'cancelled' => __('This PI is cancelled and can no longer change.'),
            'superseded' => __('This revision has been replaced by a newer one and can no longer change.'),
            default => __('This PI has been issued and can no longer change. Make a new revision to change it.'),
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['pi' => $message]);
        }
    }

    private function client(mixed $id): Party
    {
        $client = filled($id) ? Party::query()->find($id) : null;
        if (! $client || ! $client->is_client) {
            throw ValidationException::withMessages(['client_id' => __('Pick a client.')]);
        }

        return $client;
    }

    /** The given deposit %, else the client's, else the business default — as a string. */
    private function deposit(mixed $given, Party $client): string
    {
        if (filled($given)) {
            return (string) $given;
        }

        return (string) ($client->deposit_percent ?? BusinessSetting::current()->default_deposit_percent);
    }

    /**
     * The product a line creates: named after the first line of its description, with the
     * line's unit, carton spec, supplier and price.
     *
     * @return array<string, mixed>
     */
    private function productFromLine(array $data, ProformaInvoice $pi): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages(['description' => __('Describe the new product: its first line becomes the product\'s name.')]);
        }
        foreach (['unit' => __('Pick the unit of the new product.'), 'qty_per_carton' => __('Enter the quantity per carton of the new product.')] as $field => $message) {
            if (blank($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }

        return $this->withSupplierCurrency(array_filter([
            'name' => Str::limit(trim(strtok($description, "\r\n")), 250, ''),
            'description' => $description,
            'unit' => $data['unit'],
            'qty_per_carton' => $data['qty_per_carton'],
            'cbm_per_carton' => $data['cbm_per_carton'] ?? null,
            'kg_per_carton' => $data['kg_per_carton'] ?? null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'supplier_price' => $data['supplier_price'] ?? null,
            'supplier_currency' => $data['supplier_currency'] ?? null,
            'sale_price' => $data['unit_price'] ?? null,
            'sale_currency' => filled($data['unit_price'] ?? null) ? $pi->currency : null,
            'active' => true,
        ], fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * The values of a new line: what the line gives, else the product's.
     *
     * @return array<string, mixed>
     */
    private function lineValues(array $data, Product $product): array
    {
        $given = fn (string $key) => filled($data[$key] ?? null) ? $data[$key] : null;
        $productText = trim($product->name.(filled($product->description) && $product->description !== $product->name ? "\n".$product->description : ''));

        $values = [
            'description' => $given('description') ?? $productText,
            'unit' => $given('unit') ?? $product->unit,
            'cartons' => $given('cartons'),
            'qty_per_carton' => $given('qty_per_carton') ?? $product->qty_per_carton,
            'unit_price' => $given('unit_price'),
            'cbm_per_carton' => $given('cbm_per_carton') ?? $product->cbm_per_carton ?? '0',
            'kg_per_carton' => $given('kg_per_carton') ?? $product->kg_per_carton ?? '0',
            // Left empty (or hidden from someone who may not see suppliers): the product's usual
            // supplier, and its price only when the line is bought from that same supplier.
            'supplier_id' => $given('supplier_id') ?? $product->supplier_id,
        ];
        $sameSupplier = $values['supplier_id'] !== null && (int) $values['supplier_id'] === (int) $product->supplier_id;
        $values['supplier_price'] = $given('supplier_price') ?? ($sameSupplier ? $product->supplier_price : null);
        $values['supplier_currency'] = $given('supplier_currency') ?? ($sameSupplier && $given('supplier_price') === null ? $product->supplier_currency : null);
        foreach (['cartons' => __('Enter the number of cartons.'), 'qty_per_carton' => __('Enter the quantity per carton: the product has none.'),
            'unit_price' => __('Enter the price.')] as $field => $message) {
            if (blank($values[$field])) {
                throw ValidationException::withMessages([$field => $message]);
            }
        }

        return $this->withSupplierCurrency($values);
    }

    /** A supplier price without a currency is in the business supplier currency. */
    private function withSupplierCurrency(array $values, ?string $current = null): array
    {
        $currency = array_key_exists('supplier_currency', $values) ? $values['supplier_currency'] : $current;
        if (filled($values['supplier_price'] ?? null) && blank($currency)) {
            $values['supplier_currency'] = BusinessSetting::current()->supplier_currency;
        }

        return $values;
    }

    /** @return array<string, mixed> */
    private function headerValues(ProformaInvoice $pi): array
    {
        return Arr::only($pi->getAttributes(), [...self::HEADER, 'terms_text']);
    }

    /** @return array<string, mixed> */
    private function lineAudit(PiLine $line): array
    {
        return Arr::only($line->getAttributes(), ['id', 'line_no', 'product_id', 'description', 'unit', 'cartons', 'qty_per_carton',
            'unit_price', 'amount', 'cbm_per_carton', 'kg_per_carton', 'supplier_id', 'supplier_price', 'supplier_currency']);
    }
}
