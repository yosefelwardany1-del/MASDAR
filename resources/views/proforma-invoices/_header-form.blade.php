{{-- The PI header, shared by create and edit. $pi is null on create. --}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="client_id">{{ __('Client') }} <span class="text-danger">*</span></label>
        <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror" required>
            <option value="">{{ __('Pick a client') }}</option>
            @foreach($clients as $client)
                <option value="{{ $client->id }}" @selected((string) old('client_id', $pi?->client_id) === (string) $client->id)>{{ $client->name }} ({{ $client->code }})</option>
            @endforeach
        </select>
        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        @if($clients->isEmpty())<div class="form-text text-warning">{{ __('You have no active clients yet.') }}</div>@endif
    </div>
    <div class="col-md-6">
        <label class="form-label" for="shipped_to">{{ __('Shipped to') }}</label>
        <input id="shipped_to" name="shipped_to" dir="ltr" class="form-control @error('shipped_to') is-invalid @enderror" value="{{ old('shipped_to', $pi?->shipped_to) }}" maxlength="255">
        <div class="form-text">{{ __('Left empty, the client\'s usual destination is used.') }}</div>
        @error('shipped_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="po_number">{{ __('PO number') }}</label>
        <input id="po_number" name="po_number" dir="ltr" class="form-control @error('po_number') is-invalid @enderror" value="{{ old('po_number', $pi?->po_number) }}" maxlength="80">
        @error('po_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="po_date">{{ __('PO date') }}</label>
        <input id="po_date" name="po_date" type="date" class="form-control @error('po_date') is-invalid @enderror" value="{{ old('po_date', $pi?->po_date?->toDateString()) }}">
        @error('po_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="currency">{{ __('Currency') }}</label>
        <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
            @foreach($currencies as $code => $name)
                <option value="{{ $code }}" @selected(old('currency', $pi?->currency ?? $business->pi_currency) === $code)>{{ $code }} — {{ $name }}</option>
            @endforeach
        </select>
        @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="deposit_percent">{{ __('Deposit %') }}</label>
        <input id="deposit_percent" name="deposit_percent" type="number" step="0.01" min="0" max="100" dir="ltr"
               class="form-control @error('deposit_percent') is-invalid @enderror" value="{{ old('deposit_percent', $pi?->deposit_percent) }}"
               placeholder="{{ $business->default_deposit_percent }}">
        <div class="form-text">{{ __('Left empty: the client\'s deposit, else the business default (:percent%).', ['percent' => rtrim(rtrim((string) $business->default_deposit_percent, '0'), '.')]) }}</div>
        @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-8">
        <label class="form-label" for="notes">{{ __('Internal notes') }}</label>
        <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $pi?->notes) }}</textarea>
        <div class="form-text">{{ __('Not printed on the PI.') }}</div>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
