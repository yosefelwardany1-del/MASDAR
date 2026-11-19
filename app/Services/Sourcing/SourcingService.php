<?php

namespace App\Services\Sourcing;

use App\Models\Lookup;
use App\Models\Party;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\SourcingRequest;
use App\Models\SupplierQuote;
use App\Models\User;
use App\Services\Hr\Money;
use App\Services\Numbering\NumberingService;
use App\Services\Quotations\QuotationService;
use App\Support\Access\Visibility;
use App\Support\Audit\AuditTrail;
use App\Support\Database\Refusal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of sourcing_requests and supplier_quotes (spec §5.8). A request gathers
 * factories' quotes while open; choosing one decides it (its quotes then never change, trigger)
 * and the chosen factory and price can go onto a draft quotation line through QuotationService.
 * Every step needs sourcing.edit and the right to see supplier prices.
 */
class SourcingService
{
    public function __construct(private NumberingService $numbers, private QuotationService $quotations) {}

    /** @param array<string, mixed> $data description, product_id?, client_id?, unit?, quantity?, target_price?, target_currency?, needed_by?, notes? */
    public function create(array $data, User $by): SourcingRequest
    {
        $this->authorize($by);
        $values = $this->values($data, $by);

        return $this->write(function () use ($values, $by) {
            $request = SourcingRequest::create($values + ['number' => $this->numbers->next('sourcing'), 'status' => 'open', 'created_by' => $by->id]);
            AuditTrail::record('created', $request, [], $values);

            return $request;
        });
    }

    public function update(SourcingRequest $request, array $data, User $by): SourcingRequest
    {
        $this->authorize($by);
        $values = $this->values($data, $by);

        return $this->write(function () use ($request, $values) {
            $request = $this->lockedOpen($request);
            $old = $request->only(array_keys($values));
            $request->update($values);
            AuditTrail::record('updated', $request, $old, $values);

            return $request;
        });
    }

    /** @param array<string, mixed> $data supplier_id, unit_price, currency, moq?, lead_time_days?, qty_per_carton?, cbm_per_carton?, kg_per_carton?, received_on?, valid_until?, note? */
    public function addQuote(SourcingRequest $request, array $data, User $by): SupplierQuote
    {
        $this->authorize($by);
        $values = $this->quoteValues($data);

        return $this->write(function () use ($request, $values, $by) {
            $request = $this->lockedOpen($request);
            $quote = SupplierQuote::create($values + ['sourcing_request_id' => $request->id, 'created_by' => $by->id]);
            AuditTrail::record('quote_added', $request, [], $values);

            return $quote;
        });
    }

    public function updateQuote(SupplierQuote $quote, array $data, User $by): SupplierQuote
    {
        $this->authorize($by);
        $values = $this->quoteValues($data);

        return $this->write(function () use ($quote, $values) {
            $this->lockedOpen($quote->request);
            $old = $quote->only(array_keys($values));
            $quote->update($values);
            AuditTrail::record('quote_updated', $quote->request, $old, $values);

            return $quote;
        });
    }

    public function deleteQuote(SupplierQuote $quote, User $by): void
    {
        $this->authorize($by);

        $this->write(function () use ($quote) {
            $this->lockedOpen($quote->request);
            $old = $quote->only(['supplier_id', 'unit_price', 'currency']);
            $quote->delete();
            AuditTrail::record('quote_removed', $quote->request, $old, []);
        });
    }

    /** Decide the request on one of its quotes. */
    public function choose(SupplierQuote $quote, User $by): SourcingRequest
    {
        $this->authorize($by);

        return $this->write(function () use ($quote, $by) {
            $request = $this->lockedOpen($quote->request);
            if ($quote->isExpired()) {
                throw ValidationException::withMessages(['quote' => __('That quote expired on :date. Ask the factory to confirm it, then record it again.', ['date' => $quote->valid_until->toDateString()])]);
            }
            $request->update(['status' => 'chosen', 'chosen_quote_id' => $quote->id, 'decided_by' => $by->id, 'decided_at' => now()]);
            AuditTrail::record('chosen', $request, ['status' => 'open'], ['status' => 'chosen', 'supplier_id' => $quote->supplier_id, 'unit_price' => (string) $quote->unit_price, 'currency' => $quote->currency]);

            return $request;
        });
    }

    /** Close without choosing (the client dropped it), or reopen a decided request. */
    public function close(SourcingRequest $request, User $by): SourcingRequest
    {
        $this->authorize($by);

        return $this->write(function () use ($request, $by) {
            $request = $this->lockedOpen($request);
            $request->update(['status' => 'closed', 'decided_by' => $by->id, 'decided_at' => now()]);
            AuditTrail::record('closed', $request, ['status' => 'open'], ['status' => 'closed']);

            return $request;
        });
    }

    public function reopen(SourcingRequest $request, User $by): SourcingRequest
    {
        $this->authorize($by);

        return $this->write(function () use ($request) {
            $request = SourcingRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($request->isOpen()) {
                return $request;
            }
            if ($request->quotation_line_id !== null) {
                throw ValidationException::withMessages(['request' => __('The chosen quote is already on a quotation; it stays decided.')]);
            }
            $old = ['status' => $request->status];
            $request->update(['status' => 'open', 'chosen_quote_id' => null, 'decided_by' => null, 'decided_at' => null]);
            AuditTrail::record('reopened', $request, $old, ['status' => 'open']);

            return $request;
        });
    }

    /**
     * Put the chosen quote on a draft quotation as a line: the factory and its price, its packing,
     * and the selling price given here (QuotationService adds it, so its rules hold).
     */
    public function toQuotation(SourcingRequest $request, Quotation $quotation, string $unitPrice, User $by): QuotationLine
    {
        $this->authorize($by);
        $request = SourcingRequest::query()->with(['chosenQuote', 'product'])->findOrFail($request->id);
        $quote = $request->chosenQuote;
        if (! $quote || $request->status !== 'chosen') {
            throw ValidationException::withMessages(['request' => __('Choose a factory\'s quote first.')]);
        }
        if ($request->quotation_line_id !== null) {
            throw ValidationException::withMessages(['request' => __('This request is already on a quotation.')]);
        }
        if ($request->client_id !== null && $quotation->client_id !== $request->client_id) {
            throw ValidationException::withMessages(['quotation_id' => __('Choose a quotation of the request\'s client.')]);
        }

        return DB::transaction(function () use ($request, $quotation, $quote, $unitPrice, $by) {
            $line = $this->quotations->addLine($quotation, array_filter([
                'product_id' => $request->product_id,
                'description' => $request->description,
                'unit' => $request->unit ?: $request->product?->unit,
                'unit_price' => $unitPrice,
                'qty_per_carton' => $quote->qty_per_carton !== null ? (string) $quote->qty_per_carton : null,
                'moq' => $quote->moq !== null ? (string) $quote->moq : null,
                'cbm_per_carton' => $quote->cbm_per_carton !== null ? (string) $quote->cbm_per_carton : null,
                'kg_per_carton' => $quote->kg_per_carton !== null ? (string) $quote->kg_per_carton : null,
                'supplier_id' => $quote->supplier_id,
                'supplier_price' => (string) $quote->unit_price,
                'supplier_currency' => $quote->currency,
            ], fn ($v) => $v !== null && $v !== ''), $by);
            SourcingRequest::query()->whereKey($request->id)->update(['quotation_line_id' => $line->id]);
            AuditTrail::record('quoted', $request, [], ['quotation' => $quotation->number, 'line' => $line->line_no, 'unit_price' => $unitPrice]);

            return $line;
        });
    }

    // ---------------------------------------------------------------- helpers

    private function values(array $data, User $by): array
    {
        $description = trim((string) ($data['description'] ?? ''));
        $product = filled($data['product_id'] ?? null) ? Product::query()->find((int) $data['product_id']) : null;
        if ($description === '' && $product) {
            $description = $product->name;
        }
        $errors = [];
        if ($description === '') {
            $errors['description'] = __('Describe what is wanted, or choose a product.');
        }
        $client = filled($data['client_id'] ?? null) ? Party::query()->clients()->find((int) $data['client_id']) : null;
        if (filled($data['client_id'] ?? null) && (! $client || (Visibility::ownClientsOnly($by) && (int) $client->account_manager_id !== (int) $by->id))) {
            $errors['client_id'] = __('Choose a client.');
        }
        $unit = filled($data['unit'] ?? null) ? (string) $data['unit'] : $product?->unit;
        if ($unit !== null && ! array_key_exists($unit, Lookup::options('unit'))) {
            $errors['unit'] = __('Choose a unit from the list.');
        }
        $targetCurrency = filled($data['target_currency'] ?? null) ? strtoupper((string) $data['target_currency']) : null;
        if (filled($data['target_price'] ?? null) && ! array_key_exists((string) $targetCurrency, Lookup::options('currency'))) {
            $errors['target_currency'] = __('Choose the currency of the target price.');
        }
        $neededBy = filled($data['needed_by'] ?? null) ? (string) $data['needed_by'] : null;
        if ($neededBy !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $neededBy)) {
            $errors['needed_by'] = __('Enter a valid date.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'description' => mb_substr($description, 0, 2000),
            'product_id' => $product?->id,
            'client_id' => $client?->id,
            'unit' => $unit,
            'quantity' => filled($data['quantity'] ?? null) ? Money::amount($data['quantity'], 'quantity', true) : null,
            'target_price' => filled($data['target_price'] ?? null) ? Money::amount($data['target_price'], 'target_price') : null,
            'target_currency' => filled($data['target_price'] ?? null) ? $targetCurrency : null,
            'needed_by' => $neededBy,
            'notes' => filled($data['notes'] ?? null) ? mb_substr(trim((string) $data['notes']), 0, 5000) : null,
        ];
    }

    private function quoteValues(array $data): array
    {
        $errors = [];
        if (! Party::query()->suppliers()->whereKey((int) ($data['supplier_id'] ?? 0))->exists()) {
            $errors['supplier_id'] = __('Choose a supplier.');
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if (! array_key_exists($currency, Lookup::options('currency'))) {
            $errors['currency'] = __('Choose a currency from the list of currencies.');
        }
        $receivedOn = filled($data['received_on'] ?? null) ? (string) $data['received_on'] : now()->toDateString();
        $validUntil = filled($data['valid_until'] ?? null) ? (string) $data['valid_until'] : null;
        foreach (['received_on' => $receivedOn, 'valid_until' => $validUntil] as $field => $date) {
            if ($date !== null && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $errors[$field] = __('Enter a valid date.');
            }
        }
        if ($validUntil !== null && $validUntil < $receivedOn) {
            $errors['valid_until'] = __('A quote is valid until a date on or after it was received.');
        }
        $lead = filled($data['lead_time_days'] ?? null) ? (string) $data['lead_time_days'] : null;
        if ($lead !== null && (! ctype_digit($lead) || (int) $lead > 1000)) {
            $errors['lead_time_days'] = __('Enter the lead time in whole days.');
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        $optional = fn (string $key, bool $positive = false) => filled($data[$key] ?? null) ? Money::amount($data[$key], $key, $positive) : null;

        return [
            'supplier_id' => (int) $data['supplier_id'],
            'unit_price' => Money::amount($data['unit_price'] ?? null, 'unit_price'),
            'currency' => $currency,
            'moq' => $optional('moq'),
            'lead_time_days' => $lead !== null ? (int) $lead : null,
            'qty_per_carton' => $optional('qty_per_carton', true),
            'cbm_per_carton' => $optional('cbm_per_carton'),
            'kg_per_carton' => $optional('kg_per_carton'),
            'received_on' => $receivedOn,
            'valid_until' => $validUntil,
            'note' => filled($data['note'] ?? null) ? mb_substr(trim((string) $data['note']), 0, 2000) : null,
        ];
    }

    private function lockedOpen(SourcingRequest $request): SourcingRequest
    {
        $request = SourcingRequest::query()->lockForUpdate()->findOrFail($request->id);
        if (! $request->isOpen()) {
            throw ValidationException::withMessages(['request' => __(':number is decided; reopen it to change its quotes.', ['number' => $request->number])]);
        }

        return $request;
    }

    private function authorize(User $by): void
    {
        if (! $by->can('sourcing.edit') || ! Visibility::canSeeSupplierPrices($by)) {
            throw new AuthorizationException(__('You may not do this.'));
        }
    }

    private function write(callable $write): mixed
    {
        try {
            return DB::transaction($write);
        } catch (QueryException $e) {
            if (in_array($e->getCode(), ['23514', '23001', 'P0001'], true)) {
                throw ValidationException::withMessages(['request' => Refusal::message($e)]);
            }
            throw $e;
        }
    }
}
