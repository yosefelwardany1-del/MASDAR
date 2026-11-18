{{-- The quotation's header: $quotation (null when new), $clients, $currencies, $settings, $preset. --}}
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="q-client">{{ __('Client') }}<span class="app-req" aria-hidden="true">*</span></label>
        <select name="client_id" id="q-client" class="form-select @error('client_id') is-invalid @enderror" required>
            <option value="">{{ __('Choose…') }}</option>
            @foreach($clients as $c)<option value="{{ $c->id }}" @selected((int) old('client_id', $quotation?->client_id ?? ($preset ?? null)) === $c->id)>{{ $c->name }} · {{ $c->code }}</option>@endforeach
        </select>
        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="q-currency">{{ __('Currency') }}</label>
        <select name="currency" id="q-currency" class="form-select">
            @foreach($currencies as $code => $name)<option value="{{ $code }}" @selected(old('currency', $quotation?->currency ?? $settings->pi_currency) === $code)>{{ $code }}</option>@endforeach
        </select>
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="q-incoterm">{{ __('Incoterm') }}</label>
        <input name="incoterm" id="q-incoterm" dir="ltr" class="form-control" value="{{ old('incoterm', $quotation?->incoterm ?? $settings->default_incoterm) }}" placeholder="FOB Ningbo">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="q-date">{{ __('Date') }}</label>
        <input type="date" name="quoted_on" id="q-date" class="form-control @error('quoted_on') is-invalid @enderror" value="{{ old('quoted_on', $quotation?->quoted_on?->format('Y-m-d') ?? now()->toDateString()) }}">
    </div>
    <div class="col-6 col-md-3">
        <label class="form-label" for="q-valid">{{ __('Valid until') }}</label>
        <input type="date" name="valid_until" id="q-valid" class="form-control @error('valid_until') is-invalid @enderror" value="{{ old('valid_until', $quotation?->valid_until?->format('Y-m-d')) }}" aria-describedby="q-valid-help">
        @error('valid_until')<div class="invalid-feedback">{{ $message }}</div>@enderror
        <div class="form-text" id="q-valid-help">{{ trans_choice('{1} Empty: one day after the date.|[2,*] Empty: :count days after the date.', $settings->quotation_valid_days, ['count' => $settings->quotation_valid_days]) }}</div>
    </div>
    <div class="col-md-6">
        <label class="form-label" for="q-terms">{{ __('Terms shown to the client') }}</label>
        <textarea name="terms" id="q-terms" rows="2" dir="ltr" class="form-control" placeholder="{{ __('e.g. 30% deposit, 70% before shipping; prices valid for the quantities quoted') }}">{{ old('terms', $quotation?->terms) }}</textarea>
    </div>
    <div class="col-12">
        <label class="form-label" for="q-notes">{{ __('Internal notes') }}</label>
        <textarea name="notes" id="q-notes" rows="2" dir="auto" class="form-control" aria-describedby="q-notes-help">{{ old('notes', $quotation?->notes) }}</textarea>
        <div class="form-text" id="q-notes-help">{{ __('Not printed on the quotation.') }}</div>
    </div>
</div>
