{{-- The fields of a PI line, for adding ($line null) or changing one. $formKey names the form, so
     old input and errors after a refused save return to the form they came from. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;

    $mine = old('form') === $formKey;
    $value = fn (string $field, $default = null) => $mine ? old($field, $default) : $default;
    $error = fn (string $field) => $mine ? $errors->first($field) : null;
    $id = fn (string $field) => $formKey.'-'.$field;
    $adding = $line === null;
@endphp
<input type="hidden" name="form" value="{{ $formKey }}">
<div class="row g-2">
    @if($adding)
        <div class="col-md-6">
            <label class="form-label" for="{{ $id('product') }}">{{ __('Product') }} <span class="text-danger">*</span></label>
            <select id="{{ $id('product') }}" name="product" class="form-select @if($error('product')) is-invalid @endif" data-app-product-select required>
                <option value="">{{ __('Pick a product') }}</option>
                @can('products.edit')
                    <option value="new" @selected($value('product') === 'new')>{{ __('New product (made from this line)') }}</option>
                @endcan
                @foreach($products as $product)
                    <option value="{{ $product->id }}" @selected((string) $value('product') === (string) $product->id)>{{ $product->code }} — {{ $product->name }}</option>
                @endforeach
            </select>
            @if($error('product'))<div class="invalid-feedback">{{ $error('product') }}</div>@endif
            <div class="form-text">{{ __('An existing product fills in what you leave empty: description, unit, quantity per carton, CBM and KG.') }}</div>
        </div>
        <div class="col-md-6" data-app-new-product>
            <label class="form-label" for="{{ $id('photo') }}">{{ __('Photo of the new product') }}</label>
            <input id="{{ $id('photo') }}" type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="form-control @if($error('photo')) is-invalid @endif">
            @if($error('photo'))<div class="invalid-feedback">{{ $error('photo') }}</div>@endif
        </div>
    @endif
    <div class="col-12">
        <label class="form-label" for="{{ $id('description') }}">{{ __('Item name & description') }}</label>
        <textarea id="{{ $id('description') }}" name="description" rows="2" dir="ltr" class="form-control @if($error('description')) is-invalid @endif"
                  placeholder="{{ $adding ? __('Left empty: the product\'s name and description. For a new product, the first line becomes its name.') : '' }}">{{ $value('description', $line?->description) }}</textarea>
        @if($error('description'))<div class="invalid-feedback">{{ $error('description') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('cartons') }}">{{ __('Cartons') }} <span class="text-danger">*</span></label>
        <input id="{{ $id('cartons') }}" name="cartons" type="number" step="any" min="0" dir="ltr" required class="form-control @if($error('cartons')) is-invalid @endif" value="{{ $value('cartons', PiAmounts::plain($line?->cartons)) }}">
        @if($error('cartons'))<div class="invalid-feedback">{{ $error('cartons') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('qty_per_carton') }}">{{ __('Qty per carton') }}</label>
        <input id="{{ $id('qty_per_carton') }}" name="qty_per_carton" type="number" step="any" min="0" dir="ltr" @unless($adding) required @endunless class="form-control @if($error('qty_per_carton')) is-invalid @endif" value="{{ $value('qty_per_carton', PiAmounts::plain($line?->qty_per_carton)) }}">
        @if($error('qty_per_carton'))<div class="invalid-feedback">{{ $error('qty_per_carton') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('unit') }}">{{ __('Unit') }}</label>
        <select id="{{ $id('unit') }}" name="unit" class="form-select @if($error('unit')) is-invalid @endif" @unless($adding) required @endunless>
            @if($adding)<option value="">{{ __('The product\'s unit') }}</option>@endif
            @foreach($units + ($line && ! isset($units[$line->unit]) ? [$line->unit => $line->unit] : []) as $code => $name)
                <option value="{{ $code }}" @selected((string) $value('unit', $line?->unit) === (string) $code)>{{ $name }}</option>
            @endforeach
        </select>
        @if($error('unit'))<div class="invalid-feedback">{{ $error('unit') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('unit_price') }}">{{ __('Price') }} ({{ $pi->currency }}) <span class="text-danger">*</span></label>
        <input id="{{ $id('unit_price') }}" name="unit_price" type="number" step="any" min="0" dir="ltr" required class="form-control @if($error('unit_price')) is-invalid @endif" value="{{ $value('unit_price', PiAmounts::plain($line?->unit_price)) }}">
        @if($error('unit_price'))<div class="invalid-feedback">{{ $error('unit_price') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('cbm_per_carton') }}">{{ __('CBM per carton') }}</label>
        <input id="{{ $id('cbm_per_carton') }}" name="cbm_per_carton" type="number" step="any" min="0" dir="ltr" class="form-control @if($error('cbm_per_carton')) is-invalid @endif" value="{{ $value('cbm_per_carton', PiAmounts::plain($line?->cbm_per_carton)) }}">
        @if($error('cbm_per_carton'))<div class="invalid-feedback">{{ $error('cbm_per_carton') }}</div>@endif
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label" for="{{ $id('kg_per_carton') }}">{{ __('KG per carton') }}</label>
        <input id="{{ $id('kg_per_carton') }}" name="kg_per_carton" type="number" step="any" min="0" dir="ltr" class="form-control @if($error('kg_per_carton')) is-invalid @endif" value="{{ $value('kg_per_carton', PiAmounts::plain($line?->kg_per_carton)) }}">
        @if($error('kg_per_carton'))<div class="invalid-feedback">{{ $error('kg_per_carton') }}</div>@endif
    </div>
    @if($seeSuppliers)
        <div class="col-md-6">
            <label class="form-label" for="{{ $id('supplier_id') }}">{{ __('Supplier') }}</label>
            <select id="{{ $id('supplier_id') }}" name="supplier_id" class="form-select @if($error('supplier_id')) is-invalid @endif">
                <option value="">{{ $adding ? __('The product\'s usual supplier, if any') : __('None') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected((string) $value('supplier_id', $line?->supplier_id) === (string) $supplier->id)>{{ $supplier->name }} ({{ $supplier->code }})</option>
                @endforeach
            </select>
            @if($error('supplier_id'))<div class="invalid-feedback">{{ $error('supplier_id') }}</div>@endif
            <div class="form-text">{{ __('Never printed on the PI.') }}</div>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="{{ $id('supplier_price') }}">{{ __('Supplier price') }}</label>
            <input id="{{ $id('supplier_price') }}" name="supplier_price" type="number" step="any" min="0" dir="ltr" class="form-control @if($error('supplier_price')) is-invalid @endif" value="{{ $value('supplier_price', PiAmounts::plain($line?->supplier_price)) }}">
            @if($error('supplier_price'))<div class="invalid-feedback">{{ $error('supplier_price') }}</div>@endif
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label" for="{{ $id('supplier_currency') }}">{{ __('Supplier currency') }}</label>
            <select id="{{ $id('supplier_currency') }}" name="supplier_currency" class="form-select @if($error('supplier_currency')) is-invalid @endif">
                <option value="">{{ __('Business default') }}</option>
                @foreach($currencies as $code => $name)
                    <option value="{{ $code }}" @selected((string) $value('supplier_currency', $line?->supplier_currency) === (string) $code)>{{ $code }}</option>
                @endforeach
            </select>
            @if($error('supplier_currency'))<div class="invalid-feedback">{{ $error('supplier_currency') }}</div>@endif
        </div>
    @endif
</div>
