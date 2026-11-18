{{-- The claim's facts. $claim (null when opening), $preset, $kinds, $currencies, $orders, $suppliers, $pis, $clients, $seesSuppliers. --}}
@php
    $v = fn (string $field) => old($field, $claim?->{$field} ?? ($preset[$field] ?? null));
@endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="kind">{{ __('What is wrong') }}</label>
        <select id="kind" name="kind" class="form-select @error('kind') is-invalid @enderror" required>
            <option value="">—</option>
            @foreach($kinds as $code => $label)<option value="{{ $code }}" @selected($v('kind') === $code)>{{ $label }}</option>@endforeach
        </select>
        @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="opened_on">{{ __('Opened on') }}</label>
        <input id="opened_on" type="date" name="opened_on" value="{{ $v('opened_on') instanceof \DateTimeInterface ? $v('opened_on')->format('Y-m-d') : ($v('opened_on') ?? now()->toDateString()) }}" class="form-control @error('opened_on') is-invalid @enderror">
        @error('opened_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    @if($seesSuppliers)
        <div class="col-md-6">
            <label class="form-label" for="supplier_order_id">{{ __('Against the purchase order') }}</label>
            <select id="supplier_order_id" name="supplier_order_id" class="form-select @error('supplier_order_id') is-invalid @enderror">
                <option value="">{{ __('None') }}</option>
                @foreach($orders as $o)<option value="{{ $o->id }}" @selected((int) $v('supplier_order_id') === $o->id)>{{ $o->number }} · {{ $o->supplier?->name }}</option>@endforeach
            </select>
            @error('supplier_order_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="supplier_id">{{ __('or the supplier alone') }}</label>
            <select id="supplier_id" name="supplier_id" class="form-select @error('supplier_id') is-invalid @enderror">
                <option value="">{{ __('None') }}</option>
                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) $v('supplier_id') === $s->id)>{{ $s->name }} ({{ $s->code }})</option>@endforeach
            </select>
            <div class="form-text">{{ __('A purchase order names its supplier; choose one only for a claim with no order.') }}</div>
        </div>
    @endif

    <div class="col-md-6">
        <label class="form-label" for="proforma_invoice_id">{{ __('With the client, on the PI') }}</label>
        <select id="proforma_invoice_id" name="proforma_invoice_id" class="form-select @error('proforma_invoice_id') is-invalid @enderror">
            <option value="">{{ __('None') }}</option>
            @foreach($pis as $p)<option value="{{ $p->id }}" @selected((int) $v('proforma_invoice_id') === $p->id)>{{ $p->reference() }} · {{ $p->client?->name }}</option>@endforeach
        </select>
        @error('proforma_invoice_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="client_id">{{ __('or the client alone') }}</label>
        <select id="client_id" name="client_id" class="form-select @error('client_id') is-invalid @enderror">
            <option value="">{{ __('None') }}</option>
            @foreach($clients as $c)<option value="{{ $c->id }}" @selected((int) $v('client_id') === $c->id)>{{ $c->name }} ({{ $c->code }})</option>@endforeach
        </select>
    </div>

    <div class="col-12">
        <label class="form-label" for="description">{{ __('What happened') }}</label>
        <textarea id="description" name="description" rows="3" class="form-control @error('description') is-invalid @enderror" required dir="auto">{{ $v('description') }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="cartons">{{ __('Cartons affected') }}</label>
        <input id="cartons" name="cartons" inputmode="decimal" value="{{ $v('cartons') !== null ? rtrim(rtrim((string) $v('cartons'), '0'), '.') : '' }}" class="form-control @error('cartons') is-invalid @enderror">
        @error('cartons')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="claimed_amount">{{ __('Amount claimed') }}</label>
        <input id="claimed_amount" name="claimed_amount" inputmode="decimal" value="{{ $v('claimed_amount') !== null ? rtrim(rtrim((string) $v('claimed_amount'), '0'), '.') : '' }}" class="form-control @error('claimed_amount') is-invalid @enderror">
        @error('claimed_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="currency">{{ __('Currency') }}</label>
        <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
            <option value="">{{ __('The document\'s') }}</option>
            @foreach($currencies as $code => $label)<option value="{{ $code }}" @selected($v('currency') === $code)>{{ $code }} · {{ $label }}</option>@endforeach
        </select>
        @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
