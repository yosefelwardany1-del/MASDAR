{{-- A note's header. $kind, $note (null when new), $preset, $claims, $pis, $orders, $parties, $currencies. --}}
@php
    $v = fn (string $field) => old($field, $note?->{$field} ?? ($preset[$field] ?? null));
@endphp
<div class="row g-3">
    @if($kind === 'credit')
        <div class="col-md-6">
            <label class="form-label" for="proforma_invoice_id">{{ __('On the PI') }}</label>
            <select id="proforma_invoice_id" name="proforma_invoice_id" class="form-select @error('proforma_invoice_id') is-invalid @enderror">
                <option value="">{{ __('None') }}</option>
                @foreach($pis as $p)<option value="{{ $p->id }}" @selected((int) $v('proforma_invoice_id') === $p->id)>{{ $p->reference() }} · {{ $p->client?->name }} · {{ $p->currency }}</option>@endforeach
            </select>
            <div class="form-text">{{ __('The note is in the PI\'s currency and lowers what is due on it.') }}</div>
        </div>
    @else
        <div class="col-md-6">
            <label class="form-label" for="supplier_order_id">{{ __('On the purchase order') }}</label>
            <select id="supplier_order_id" name="supplier_order_id" class="form-select @error('supplier_order_id') is-invalid @enderror">
                <option value="">{{ __('None') }}</option>
                @foreach($orders as $o)<option value="{{ $o->id }}" @selected((int) $v('supplier_order_id') === $o->id)>{{ $o->number }} · {{ $o->supplier?->name }} · {{ $o->currency }}</option>@endforeach
            </select>
            <div class="form-text">{{ __('The note is in the order\'s currency and lowers what is still to pay the factory.') }}</div>
        </div>
    @endif
    <div class="col-md-6">
        <label class="form-label" for="party_id">{{ $kind === 'credit' ? __('or the client') : __('or the supplier') }}</label>
        <select id="party_id" name="party_id" class="form-select @error('party_id') is-invalid @enderror">
            <option value="">{{ __('From the document') }}</option>
            @foreach($parties as $p)<option value="{{ $p->id }}" @selected((int) $v('party_id') === $p->id)>{{ $p->name }} ({{ $p->code }})</option>@endforeach
        </select>
        @error('party_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="claim_id">{{ __('Settles the claim') }}</label>
        <select id="claim_id" name="claim_id" class="form-select @error('claim_id') is-invalid @enderror">
            <option value="">{{ __('None') }}</option>
            @foreach($claims as $c)<option value="{{ $c->id }}" @selected((int) $v('claim_id') === $c->id)>{{ $c->number }} · {{ \Illuminate\Support\Str::limit($c->description, 50) }}</option>@endforeach
        </select>
        @error('claim_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="currency">{{ __('Currency') }}</label>
        <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
            <option value="">{{ __('The document\'s') }}</option>
            @foreach($currencies as $code => $label)<option value="{{ $code }}" @selected($v('currency') === $code)>{{ $code }} · {{ $label }}</option>@endforeach
        </select>
        @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label" for="reason">{{ __('What the note is for') }}</label>
        <textarea id="reason" name="reason" rows="2" class="form-control @error('reason') is-invalid @enderror" required dir="auto">{{ $v('reason') }}</textarea>
        <div class="form-text">{{ __('Printed on the note, so in English.') }}</div>
        @error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>
