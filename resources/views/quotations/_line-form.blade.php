{{-- A quotation line's fields. $line (null when new), $prefix (id prefix), $units, $currencies,
     $products (new lines only), $suppliers, $seesSupplier. --}}
@php
    // The page holds a form per line and one to add a line: a refusal is shown in the form that
    // was sent, under its field, with what was typed there (the hidden _form names it).
    $mine = old('_form') === $prefix || (old('_form') === null && ! $line);
    $v = fn (string $f) => $mine ? old($f) : ($line ? (is_numeric($line->{$f}) ? rtrim(rtrim((string) $line->{$f}, '0'), '.') : $line->{$f}) : null);
    $bad = fn (string $f) => $mine && $errors->has($f) ? ' is-invalid' : '';
    $feedback = fn (string $f) => $mine && $errors->has($f) ? new \Illuminate\Support\HtmlString('<div class="invalid-feedback">'.e($errors->first($f)).'</div>') : '';
@endphp
<input type="hidden" name="_form" value="{{ $prefix }}">
@if($mine && $errors->has('quotation'))<div class="text-danger small mb-2">{{ $errors->first('quotation') }}</div>@endif
<div class="row g-2">
    @if(! $line)
        <div class="col-md-6">
            <label class="form-label small" for="{{ $prefix }}-product">{{ __('Product') }}</label>
            <select name="product_id" id="{{ $prefix }}-product" class="form-select form-select-sm{{ $bad('product_id') }}" aria-describedby="{{ $prefix }}-product-help">
                <option value="">{{ __('A new item (describe it below)') }}</option>
                @foreach($products as $p)<option value="{{ $p->id }}" @selected((int) $v('product_id') === $p->id)>{{ $p->code }} · {{ $p->name }}</option>@endforeach
            </select>
            {{ $feedback('product_id') }}
            <div class="form-text" id="{{ $prefix }}-product-help">{{ __('A product fills in what you leave empty: its name, unit, packing and price.') }}</div>
        </div>
    @endif
    <div class="col-md-{{ $line ? 12 : 6 }}">
        <label class="form-label small" for="{{ $prefix }}-desc">{{ __('Item name & description') }}</label>
        <textarea name="description" id="{{ $prefix }}-desc" rows="2" class="form-control form-control-sm{{ $bad('description') }}">{{ $v('description') }}</textarea>
        {{ $feedback('description') }}
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small" for="{{ $prefix }}-unit">{{ __('Unit') }}</label>
        <select name="unit" id="{{ $prefix }}-unit" class="form-select form-select-sm{{ $bad('unit') }}">
            <option value="">—</option>
            @foreach($units as $code => $name)<option value="{{ $code }}" @selected($v('unit') === $code)>{{ $name }}</option>@endforeach
        </select>
        {{ $feedback('unit') }}
    </div>
    @foreach(['unit_price' => __('Unit price'), 'qty_per_carton' => __('Qty per carton'), 'moq' => __('MOQ'), 'cartons' => __('Cartons (if asked)'), 'cbm_per_carton' => __('CBM per carton'), 'kg_per_carton' => __('KG per carton')] as $field => $label)
        <div class="col-6 col-md-2">
            <label class="form-label small" for="{{ $prefix }}-{{ $field }}">{{ $label }}</label>
            <input name="{{ $field }}" id="{{ $prefix }}-{{ $field }}" inputmode="decimal" dir="ltr" class="form-control form-control-sm app-num{{ $bad($field) }}" value="{{ $v($field) }}">
            {{ $feedback($field) }}
        </div>
    @endforeach
    @if($seesSupplier)
        <div class="col-md-6">
            <label class="form-label small" for="{{ $prefix }}-supplier"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Supplier') }}</label>
            <select name="supplier_id" id="{{ $prefix }}-supplier" class="form-select form-select-sm{{ $bad('supplier_id') }}">
                <option value="">—</option>
                @foreach($suppliers as $s)<option value="{{ $s->id }}" @selected((int) $v('supplier_id') === $s->id)>{{ $s->name }}</option>@endforeach
            </select>
            {{ $feedback('supplier_id') }}
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="{{ $prefix }}-sprice"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Supplier price') }}</label>
            <input name="supplier_price" id="{{ $prefix }}-sprice" inputmode="decimal" dir="ltr" class="form-control form-control-sm app-num{{ $bad('supplier_price') }}" value="{{ $v('supplier_price') }}">
            {{ $feedback('supplier_price') }}
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small" for="{{ $prefix }}-scur">{{ __('Supplier currency') }}</label>
            <select name="supplier_currency" id="{{ $prefix }}-scur" class="form-select form-select-sm{{ $bad('supplier_currency') }}">
                <option value="">—</option>
                @foreach($currencies as $code => $name)<option value="{{ $code }}" @selected($v('supplier_currency') === $code)>{{ $code }}</option>@endforeach
            </select>
            {{ $feedback('supplier_currency') }}
        </div>
    @endif
</div>
