{{-- A service invoice's header fields. $invoice (or null), $preset, $clients, $pis, $currencies. --}}
@php $v = fn (string $f) => old($f, $invoice?->{$f} ?? ($preset[$f] ?? null)); @endphp
<div class="row g-3">
    <div class="col-md-5"><label class="form-label" for="client_id">{{ __('Client') }}</label>
        <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror"><option value="">{{ __('From the PI') }}</option>@foreach($clients as $c)<option value="{{ $c->id }}" @selected((int) $v('client_id') === $c->id)>{{ $c->name }} ({{ $c->code }})</option>@endforeach</select>
        @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
    <div class="col-md-4"><label class="form-label" for="proforma_invoice_id">{{ __('For the PI (optional)') }}</label>
        <select id="proforma_invoice_id" name="proforma_invoice_id" class="form-select"><option value="">—</option>@foreach($pis as $p)<option value="{{ $p->id }}" @selected((int) $v('proforma_invoice_id') === $p->id)>{{ $p->reference() }} · {{ $p->client?->name }}</option>@endforeach</select></div>
    <div class="col-md-3"><label class="form-label" for="currency">{{ __('Currency') }}</label>
        <select id="currency" name="currency" class="form-select"><option value="">{{ __('The document\'s') }}</option>@foreach($currencies as $code => $label)<option value="{{ $code }}" @selected($v('currency') === $code)>{{ $code }}</option>@endforeach</select></div>
    <div class="col-12"><label class="form-label" for="notes">{{ __('Notes printed on the invoice') }}</label><input id="notes" name="notes" value="{{ $v('notes') }}" class="form-control" dir="auto"></div>
</div>
