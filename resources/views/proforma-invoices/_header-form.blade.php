{{-- The PI header, shared by create and edit. $pi is null on create. In three groups, as the
     document reads: who it is for, the client's order, and the money terms; then internal notes. --}}
@php
    $req = '<span class="text-danger" aria-hidden="true">*</span>';
    $businessDeposit = rtrim(rtrim((string) $business->default_deposit_percent, '0'), '.');
@endphp
<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-person-badge" aria-hidden="true"></i>{{ __('Client and destination') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="client_id">{{ __('Client') }} {!! $req !!}</label>
            <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror" required data-app-pi-client>
                <option value="">{{ __('Pick a client') }}</option>
                @foreach($clients as $client)
                    <option value="{{ $client->id }}" @selected((string) old('client_id', $pi?->client_id) === (string) $client->id)
                            data-destination="{{ $client->default_destination }}"
                            data-deposit="{{ $client->deposit_percent !== null ? rtrim(rtrim((string) $client->deposit_percent, '0'), '.') : '' }}">{{ $client->name }} ({{ $client->code }})</option>
                @endforeach
            </select>
            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @if($clients->isEmpty())<div class="form-text text-warning">{{ __('You have no active clients yet.') }}</div>@endif
        </div>
        <div class="col-md-6">
            <label class="form-label" for="shipped_to">{{ __('Shipped to') }}</label>
            <input id="shipped_to" name="shipped_to" dir="ltr" class="form-control @error('shipped_to') is-invalid @enderror" value="{{ old('shipped_to', $pi?->shipped_to) }}" maxlength="255" aria-describedby="shipped_to-help">
            <div class="form-text" id="shipped_to-help">{{ __('Left empty, the client\'s usual destination is used.') }}</div>
            @error('shipped_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ __('The client\'s order') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="po_number">{{ __('PO number') }}</label>
            <input id="po_number" name="po_number" dir="ltr" class="form-control @error('po_number') is-invalid @enderror" value="{{ old('po_number', $pi?->po_number) }}" maxlength="80">
            @error('po_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="po_date">{{ __('PO date') }}</label>
            <input id="po_date" name="po_date" type="date" class="form-control @error('po_date') is-invalid @enderror" value="{{ old('po_date', $pi?->po_date?->toDateString()) }}">
            @error('po_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-cash-coin" aria-hidden="true"></i>{{ __('Money and terms') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="currency">{{ __('Currency') }}</label>
            <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
                @foreach($currencies as $code => $name)
                    <option value="{{ $code }}" @selected(old('currency', $pi?->currency ?? $business->pi_currency) === $code)>{{ $code }} — {{ $name }}</option>
                @endforeach
            </select>
            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="deposit_percent">{{ __('Deposit %') }}</label>
            <div class="input-group has-validation">
                <input id="deposit_percent" name="deposit_percent" type="number" step="0.01" min="0" max="100" dir="ltr" inputmode="decimal"
                       class="form-control @error('deposit_percent') is-invalid @enderror" value="{{ old('deposit_percent', $pi?->deposit_percent) }}"
                       placeholder="{{ $business->default_deposit_percent }}" data-business-deposit="{{ $business->default_deposit_percent }}" aria-describedby="deposit_percent-help">
                <span class="input-group-text">%</span>
                @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="form-text" id="deposit_percent-help">{{ __('Left empty: the client\'s deposit, else the business default (:percent%).', ['percent' => $businessDeposit]) }}</div>
        </div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Internal notes') }}</legend>
    <label class="visually-hidden" for="notes">{{ __('Internal notes') }}</label>
    <textarea id="notes" name="notes" rows="3" class="form-control @error('notes') is-invalid @enderror" aria-describedby="notes-help">{{ old('notes', $pi?->notes) }}</textarea>
    <div class="form-text" id="notes-help">{{ __('Not printed on the PI.') }}</div>
    @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
</fieldset>

@push('scripts')
<script>
    // What "left empty" will use, shown as the empty fields' placeholders once a client is picked.
    (() => {
        const client = document.querySelector('[data-app-pi-client]');
        const shipped = document.getElementById('shipped_to');
        const deposit = document.getElementById('deposit_percent');
        if (! client) return;
        const sync = () => {
            const option = client.selectedOptions[0];
            shipped.placeholder = option?.dataset.destination || '';
            deposit.placeholder = option?.dataset.deposit || deposit.dataset.businessDeposit;
        };
        client.addEventListener('change', sync);
        sync();
    })();
</script>
@endpush
