<?php

namespace App\Services\Quotations;

use App\Models\BusinessSetting;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Models\ProformaInvoice;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\User;
use App\Services\Numbering\NumberingService;
use App\Services\ProformaInvoices\ProformaInvoiceService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of quotations and quotation_lines (spec §5.2). A draft is edited freely and
 * numbered from the quotation series when it is sent, so sent numbers are gapless; a sent
 * quotation never changes (triggers) but for the client's answer. An accepted quotation becomes
 * one draft PI through ProformaInvoiceService, its lines carried over.
 */
class QuotationService
{
    public const LINE = ['product_id', 'description', 'unit', 'qty_per_carton', 'cartons', 'moq', 'unit_price', 'cbm_per_carton', 'kg_per_carton',
        'supplier_id', 'supplier_price', 'supplier_currency'];

    public function __construct(private NumberingService $numbers, private ProformaInvoiceService $pis) {}

    /** @param array<string, mixed> $data client_id, currency, quoted_on, valid_until, incoterm, terms, notes */
    public function create(array $data, User $by): Quotation
    {
        $this->authorize($by, 'quotations.edit');
        $client = $this->client($data['client_id'] ?? null, $by);
        $settings = BusinessSetting::current();
        $quotedOn = $this->date($data['quoted_on'] ?? null, 'quoted_on') ?? now()->toDateString();
        $header = $this->header($data, $quotedOn, $settings) + ['client_id' => $client->id];

        return DB::transaction(function () use ($header, $by) {
            $quotation = Quotation::create($header + ['number' => 'DRAFT-'.Str::upper(Str::random(8)), 'status' => 'draft', 'created_by' => $by->id]);
            AuditTrail::record('created', $quotation, [], $header);

            return $quotation;
        });
    }

    /** @param array<string, mixed> $data the header fields */
    public function update(Quotation $quotation, array $data, User $by): Quotation
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($quotation, $data, $by) {
            $quotation = $this->lockedDraft($quotation);
            $client = $this->client($data['client_id'] ?? $quotation->client_id, $by);
            $quotedOn = $this->date($data['quoted_on'] ?? null, 'quoted_on') ?? $quotation->quoted_on->toDateString();
            $header = $this->header($data, $quotedOn, BusinessSetting::current()) + ['client_id' => $client->id];
            $old = $quotation->only(array_keys($header));
            $quotation->update($header);
            AuditTrail::record('updated', $quotation, $old, $header);

            return $quotation;
        });
    }

    /** @param array<string, mixed> $data LINE fields; a product fills what is left empty */
    public function addLine(Quotation $quotation, array $data, User $by): QuotationLine
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($quotation, $data, $by) {
            $quotation = $this->lockedDraft($quotation);
            $values = $this->line($data, null, $by);
            $line = QuotationLine::create($values + [
                'quotation_id' => $quotation->id,
                'line_no' => (int) QuotationLine::query()->where('quotation_id', $quotation->id)->max('line_no') + 1,
            ]);
            AuditTrail::record('line_added', $quotation, [], ['line_no' => $line->line_no, 'description' => Str::limit($line->description, 80), 'unit_price' => $line->unit_price]);

            return $line;
        });
    }

    /** @param array<string, mixed> $data LINE fields */
    public function updateLine(QuotationLine $line, array $data, User $by): QuotationLine
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($line, $data, $by) {
            $this->lockedDraft($line->quotation);
            $line = QuotationLine::query()->lockForUpdate()->findOrFail($line->id);
            $values = $this->line($data, $line, $by);
            $old = $line->only(array_keys($values));
            $line->update($values);
            AuditTrail::record('line_updated', $line->quotation, $old, $values);

            return $line;
        });
    }

    public function deleteLine(QuotationLine $line, User $by): void
    {
        $this->authorize($by, 'quotations.edit');
        DB::transaction(function () use ($line) {
            $quotation = $this->lockedDraft($line->quotation);
            AuditTrail::record('line_removed', $quotation, ['line_no' => $line->line_no, 'description' => Str::limit($line->description, 80)], []);
            $line->delete();
        });
    }

    /**
     * Send it to the client: numbered now, and never changed after. One whose validity has already
     * ended is sent only when the person says so ($anyway, B13): extending its date is the usual fix.
     */
    public function send(Quotation $quotation, User $by, bool $anyway = false): Quotation
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($quotation, $by, $anyway) {
            $quotation = $this->lockedDraft($quotation);
            if (! $quotation->lines()->exists()) {
                throw ValidationException::withMessages(['quotation' => __('Add at least one line before sending the quotation.')]);
            }
            if ($quotation->isPastValidity() && ! $anyway) {
                throw ValidationException::withMessages(['valid_until' => __('This quotation was valid until :date, which has passed. Extend its date before sending it, or send it anyway.', ['date' => $quotation->valid_until->toDateString()])]);
            }
            $quotation->update([
                'number' => $this->numbers->next('quotation', $quotation->quoted_on),
                'status' => 'sent', 'sent_by' => $by->id, 'sent_at' => now(),
            ]);
            AuditTrail::record('sent', $quotation, [], ['number' => $quotation->number]);

            return $quotation;
        });
    }

    public function accept(Quotation $quotation, User $by): Quotation
    {
        return $this->answer($quotation, 'accepted', $by);
    }

    public function decline(Quotation $quotation, User $by): Quotation
    {
        return $this->answer($quotation, 'declined', $by);
    }

    /**
     * The accepted quotation becomes a draft PI for the same client, in its currency, with its
     * lines: a line's proposed quantity, else one carton to be set on the PI. A sent quotation is
     * accepted on the way.
     */
    public function convert(Quotation $quotation, User $by): ProformaInvoice
    {
        $this->authorize($by, 'quotations.edit');
        $this->authorize($by, 'proforma_invoices.edit');

        return DB::transaction(function () use ($quotation, $by) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($quotation->proforma_invoice_id) {
                throw ValidationException::withMessages(['quotation' => __('This quotation is already PI :pi.', ['pi' => $quotation->proformaInvoice?->reference()])]);
            }
            // B13: past its validity the offer no longer stands — unless it was accepted while it did.
            if ($quotation->status === 'sent' && $quotation->isPastValidity()) {
                throw ValidationException::withMessages(['quotation' => $this->expiredMessage($quotation)]);
            }
            if ($quotation->status === 'sent') {
                $quotation = $this->answer($quotation, 'accepted', $by);
            }
            if ($quotation->status !== 'accepted') {
                throw ValidationException::withMessages(['quotation' => __('Only a sent or accepted quotation becomes a PI.')]);
            }

            $pi = $this->pis->createDraft(['client_id' => $quotation->client_id, 'currency' => $quotation->currency,
                'notes' => 'From quotation '.$quotation->number.'.'], $by);
            foreach ($quotation->lines as $line) {
                $this->pis->addLine($pi, array_filter([
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'unit' => $line->unit,
                    'cartons' => $line->cartons ?? '1',
                    'qty_per_carton' => bccomp((string) $line->qty_per_carton, '0', 4) > 0 ? $line->qty_per_carton : '1',
                    'unit_price' => $line->unit_price,
                    'cbm_per_carton' => $line->cbm_per_carton,
                    'kg_per_carton' => $line->kg_per_carton,
                    'supplier_id' => $line->supplier_id,
                    'supplier_price' => $line->supplier_price,
                    'supplier_currency' => $line->supplier_currency,
                ], fn ($v) => $v !== null), $by);
            }
            $quotation->update(['proforma_invoice_id' => $pi->id]);
            AuditTrail::record('converted', $quotation, [], ['pi' => $pi->reference()]);

            return $pi;
        });
    }

    /** A new draft with the same client and lines: how a sent quotation is revised. */
    public function duplicate(Quotation $quotation, User $by): Quotation
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($quotation, $by) {
            $copy = $this->create(['client_id' => $quotation->client_id, 'currency' => $quotation->currency, 'incoterm' => $quotation->incoterm,
                'terms' => $quotation->terms, 'notes' => $quotation->notes], $by);
            foreach ($quotation->lines as $line) {
                QuotationLine::create($line->only(self::LINE) + ['quotation_id' => $copy->id, 'line_no' => $line->line_no]);
            }
            AuditTrail::record('copied', $copy, [], ['from' => $quotation->number]);

            return $copy;
        });
    }

    public function deleteDraft(Quotation $quotation, User $by): void
    {
        $this->authorize($by, 'quotations.edit');
        DB::transaction(function () use ($quotation) {
            $quotation = $this->lockedDraft($quotation);
            QuotationLine::query()->where('quotation_id', $quotation->id)->delete();
            AuditTrail::record('deleted', $quotation, ['client_id' => $quotation->client_id], []);
            $quotation->delete();
        });
    }

    private function expiredMessage(Quotation $quotation): string
    {
        return __('This quotation expired on :date. Copy it (Copy to revise), give the copy a new date and send that instead.', ['date' => $quotation->valid_until->toDateString()]);
    }

    private function answer(Quotation $quotation, string $status, User $by): Quotation
    {
        $this->authorize($by, 'quotations.edit');

        return DB::transaction(function () use ($quotation, $status) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
            if ($quotation->status !== 'sent') {
                throw ValidationException::withMessages(['quotation' => __('Only a sent quotation is accepted or declined.')]);
            }
            // B13: an expired offer is not accepted; a decline is still recorded.
            if ($status === 'accepted' && $quotation->isPastValidity()) {
                throw ValidationException::withMessages(['quotation' => $this->expiredMessage($quotation)]);
            }
            $quotation->update(['status' => $status, 'decided_at' => now()]);
            AuditTrail::record($status, $quotation, [], []);

            return $quotation;
        });
    }

    /** @return array<string, mixed> */
    private function header(array $data, string $quotedOn, BusinessSetting $settings): array
    {
        $currency = strtoupper((string) ($data['currency'] ?? '') ?: $settings->pi_currency);
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            throw ValidationException::withMessages(['currency' => __('Choose a currency from the list of currencies.')]);
        }
        $validUntil = $this->date($data['valid_until'] ?? null, 'valid_until')
            ?? CarbonImmutable::parse($quotedOn)->addDays($settings->quotation_valid_days)->toDateString();
        if ($validUntil < $quotedOn) {
            throw ValidationException::withMessages(['valid_until' => __('A quotation is valid until a date on or after its own.')]);
        }

        return [
            'quoted_on' => $quotedOn, 'valid_until' => $validUntil, 'currency' => $currency,
            'incoterm' => $this->text($data['incoterm'] ?? null, 60) ?? $settings->default_incoterm,
            'terms' => $this->text($data['terms'] ?? null), 'notes' => $this->text($data['notes'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function line(array $data, ?QuotationLine $line, User $by): array
    {
        $data = array_intersect_key($data, array_flip(self::LINE));
        $product = filled($data['product_id'] ?? null) ? Product::query()->find($data['product_id']) : null;
        if (filled($data['product_id'] ?? null) && ! $product) {
            throw ValidationException::withMessages(['product_id' => __('That product does not exist.')]);
        }
        $seesSupplier = Visibility::canSeeSupplierPrices($by);
        $pick = fn (string $key, $fallback = null) => filled($data[$key] ?? null) ? $data[$key] : ($line?->{$key} ?? $fallback);
        $productText = $product ? trim($product->name.(filled($product->description) && $product->description !== $product->name ? "\n".$product->description : '')) : null;

        $values = [
            'product_id' => $product?->id ?? ($line?->product_id),
            'description' => trim((string) $pick('description', $productText)),
            'unit' => (string) $pick('unit', $product?->unit),
            'qty_per_carton' => $this->decimal($pick('qty_per_carton', $product?->qty_per_carton ?? '0'), 'qty_per_carton'),
            'cartons' => array_key_exists('cartons', $data) ? (filled($data['cartons']) ? $this->decimal($data['cartons'], 'cartons', positive: true) : null) : $line?->cartons,
            'moq' => array_key_exists('moq', $data) ? (filled($data['moq']) ? $this->decimal($data['moq'], 'moq') : null) : $line?->moq,
            'unit_price' => $this->decimal($pick('unit_price', $product?->sale_price), 'unit_price', required: true),
            'cbm_per_carton' => $this->decimal($pick('cbm_per_carton', $product?->cbm_per_carton ?? '0'), 'cbm_per_carton'),
            'kg_per_carton' => $this->decimal($pick('kg_per_carton', $product?->kg_per_carton ?? '0'), 'kg_per_carton'),
        ];
        if ($values['description'] === '') {
            throw ValidationException::withMessages(['description' => __('Describe the item, or choose a product.')]);
        }
        if (! array_key_exists($values['unit'], Lookup::options('unit')) && $values['unit'] !== $line?->unit) {
            throw ValidationException::withMessages(['unit' => __('Choose a unit from the list.')]);
        }
        // Who may not see supplier prices neither sees nor sets them: the line keeps what it has.
        if ($seesSupplier) {
            $values += [
                'supplier_id' => $pick('supplier_id', $product?->supplier_id),
                'supplier_price' => ($p = $pick('supplier_price', $product?->supplier_price)) === null ? null : $this->decimal($p, 'supplier_price'),
                'supplier_currency' => $pick('supplier_currency', $product?->supplier_currency),
            ];
            if ($values['supplier_id'] !== null && ! Party::query()->suppliers()->whereKey($values['supplier_id'])->exists()) {
                throw ValidationException::withMessages(['supplier_id' => __('Choose a supplier.')]);
            }
        }

        return $values;
    }

    private function client(mixed $id, User $by): Party
    {
        $client = filled($id) ? Party::query()->clients()->visibleTo($by)->find($id) : null;
        if (! $client) {
            throw ValidationException::withMessages(['client_id' => __('Choose the client.')]);
        }

        return $client;
    }

    private function lockedDraft(Quotation $quotation): Quotation
    {
        $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);
        if (! $quotation->isDraft()) {
            throw ValidationException::withMessages(['quotation' => __('Quotation :number has been sent and no longer changes. Copy it to revise it.', ['number' => $quotation->number])]);
        }

        return $quotation;
    }

    private function decimal(mixed $value, string $field, bool $positive = false, bool $required = false): string
    {
        $value = trim(str_replace([',', ' '], '', (string) ($value ?? '')));
        if ($value === '') {
            if ($required) {
                throw ValidationException::withMessages([$field => __('Enter a number.')]);
            }
            $value = '0';
        }
        if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $value) || ($positive && bccomp($value, '0', 4) <= 0)) {
            throw ValidationException::withMessages([$field => $positive ? __('Enter a number above zero.') : __('Enter a number of up to :n decimals, without a minus sign.', ['n' => 4])]);
        }

        return bcadd($value, '0', 4);
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', (string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a date.')]);
        }
    }

    private function text(mixed $value, int $max = 5000): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function authorize(User $user, string $permission): void
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException(__('You are not allowed to do this.'));
        }
    }
}
