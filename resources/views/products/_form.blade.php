{{-- Shared by create and edit. $product is null on create. The supplier and the supplier's
     price are on the form only for a user who may see supplier prices ($canSeeSupplier). --}}
@php
    $num = fn (string $field) => old($field, $product?->{$field} !== null ? rtrim(rtrim((string) $product->{$field}, '0'), '.') : null);
@endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="f-name">{{ __('Name (English)') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" id="f-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $product?->name) }}" maxlength="255" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="f-name_local">{{ __('Name in Chinese or Arabic') }}</label>
        <input type="text" name="name_local" id="f-name_local" class="form-control @error('name_local') is-invalid @enderror" value="{{ old('name_local', $product?->name_local) }}" maxlength="255">
        @error('name_local')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <label class="form-label" for="f-description">{{ __('Description') }}</label>
        <textarea name="description" id="f-description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $product?->description) }}</textarea>
        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        @include('parties._select', ['name' => 'unit', 'label' => __('Unit'), 'options' => $units, 'value' => $product?->unit ?? 'pcs', 'required' => true])
    </div>
    <div class="col-md-4">
        <label class="form-label" for="f-qty_per_carton">{{ __('Quantity per carton') }}</label>
        <input type="number" name="qty_per_carton" id="f-qty_per_carton" dir="ltr" step="0.0001" min="0" class="form-control @error('qty_per_carton') is-invalid @enderror" value="{{ $num('qty_per_carton') }}">
        @error('qty_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label" for="f-hs_code">{{ __('HS code') }}</label>
        <input type="text" name="hs_code" id="f-hs_code" dir="ltr" class="form-control @error('hs_code') is-invalid @enderror" value="{{ old('hs_code', $product?->hs_code) }}" maxlength="30">
        @error('hs_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

<fieldset class="mt-4">
    <legend class="h6 border-bottom pb-2">{{ __('Carton') }}</legend>
    <div class="row g-3" data-carton>
        @foreach(['carton_length_cm' => __('Length (cm)'), 'carton_width_cm' => __('Width (cm)'), 'carton_height_cm' => __('Height (cm)')] as $field => $label)
            <div class="col-md-2 col-4">
                <label class="form-label" for="f-{{ $field }}">{{ $label }}</label>
                <input type="number" name="{{ $field }}" id="f-{{ $field }}" dir="ltr" step="0.0001" min="0" class="form-control @error($field) is-invalid @enderror" value="{{ $num($field) }}" data-carton-side>
                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        @endforeach
        <div class="col-md-3 col-6">
            <label class="form-label" for="f-cbm_per_carton">{{ __('CBM per carton') }}</label>
            <input type="number" name="cbm_per_carton" id="f-cbm_per_carton" dir="ltr" step="0.0001" min="0" class="form-control @error('cbm_per_carton') is-invalid @enderror" value="{{ $num('cbm_per_carton') }}" data-carton-cbm>
            <div class="form-text">{{ __('Worked out from the size; you can change it.') }}</div>
            @error('cbm_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3 col-6">
            <label class="form-label" for="f-kg_per_carton">{{ __('Gross KG per carton') }}</label>
            <input type="number" name="kg_per_carton" id="f-kg_per_carton" dir="ltr" step="0.0001" min="0" class="form-control @error('kg_per_carton') is-invalid @enderror" value="{{ $num('kg_per_carton') }}">
            @error('kg_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>

<fieldset class="mt-4">
    <legend class="h6 border-bottom pb-2">{{ __('Prices') }}</legend>
    <div class="row g-3">
        @if($canSeeSupplier)
            <div class="col-md-4">
                @include('parties._select', ['name' => 'supplier_id', 'label' => __('Usual supplier'), 'options' => $suppliers->mapWithKeys(fn ($s) => [$s->id => $s->code.' · '.$s->name])->all(), 'value' => $product?->supplier_id])
            </div>
            <div class="col-md-4">
                <label class="form-label" for="f-supplier_price">{{ __('Supplier price') }}</label>
                <input type="number" name="supplier_price" id="f-supplier_price" dir="ltr" step="0.0001" min="0" class="form-control @error('supplier_price') is-invalid @enderror" value="{{ $num('supplier_price') }}">
                @error('supplier_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                @include('parties._select', ['name' => 'supplier_currency', 'label' => __('Supplier currency'), 'options' => $currencies, 'value' => $product?->supplier_currency ?? ($product ? null : $defaults['supplier_currency'])])
            </div>
        @endif
        <div class="col-md-4">
            <label class="form-label" for="f-sale_price">{{ __('Sale price') }}</label>
            <input type="number" name="sale_price" id="f-sale_price" dir="ltr" step="0.0001" min="0" class="form-control @error('sale_price') is-invalid @enderror" value="{{ $num('sale_price') }}">
            @error('sale_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            @include('parties._select', ['name' => 'sale_currency', 'label' => __('Sale currency'), 'options' => $currencies, 'value' => $product?->sale_currency ?? ($product ? null : $defaults['sale_currency'])])
        </div>
    </div>
</fieldset>

<fieldset class="mt-4">
    <legend class="h6 border-bottom pb-2">{{ __('Photo') }}</legend>
    <div class="d-flex flex-wrap align-items-center gap-3">
        @if($product?->photo)
            <img src="{{ route('products.photo', $product) }}" alt="" class="app-thumb" style="inline-size:96px;block-size:96px">
        @endif
        <div>
            <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" class="form-control @error('photo') is-invalid @enderror" style="max-inline-size:340px">
            <div class="form-text">{{ __('JPG, PNG or WebP, up to :size MB. A new photo replaces the old one.', ['size' => round($photoRules / 1024)]) }}</div>
            @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>

<div class="mt-4">
    <input type="hidden" name="active" value="0">
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="f-active" @checked(old('active', $product?->active ?? true))>
        <label class="form-check-label" for="f-active">{{ __('Active') }}</label>
    </div>
</div>

@push('scripts')
<script>
    // CBM per carton = L × W × H (cm) ÷ 1,000,000. Filled as the size is typed, until somebody
    // types a CBM of their own; the server does the same sum when the field is left empty.
    (() => {
        const box = document.querySelector('[data-carton]');
        if (! box) return;
        const sides = [...box.querySelectorAll('[data-carton-side]')];
        const cbm = box.querySelector('[data-carton-cbm]');
        let typed = cbm.value !== '' && cbm.value !== computed();
        function computed() {
            const [l, w, h] = sides.map((s) => parseFloat(s.value));
            return [l, w, h].every((n) => n > 0) ? (Math.round(l * w * h / 100) / 10000).toFixed(4) : '';
        }
        cbm.addEventListener('input', () => { typed = cbm.value !== ''; });
        sides.forEach((s) => s.addEventListener('input', () => { if (! typed) cbm.value = computed(); }));
    })();
</script>
@endpush
