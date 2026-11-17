{{-- Shared by create and edit. $product is null on create. The supplier and the supplier's
     price are on the form only for a user who may see supplier prices ($canSeeSupplier).
     The photo sits beside the fields: it is what staff recognise a product by. On edit,
     $removePhotoForm names the separate form that removes the photo (forms cannot nest). --}}
@php
    $num = fn (string $field) => old($field, $product?->{$field} !== null ? rtrim(rtrim((string) $product->{$field}, '0'), '.') : null);
    $req = '<span class="text-danger" aria-hidden="true">*</span>';
@endphp
<div class="app-productform">
    <div class="app-productform__main">
        <fieldset class="app-docform__section">
            <legend class="app-docform__legend"><i class="bi bi-box-seam" aria-hidden="true"></i>{{ __('The product') }}</legend>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="f-name">{{ __('Name (English)') }} {!! $req !!}</label>
                    <input type="text" name="name" id="f-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $product?->name) }}" maxlength="255" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-name_local">{{ __('Name in Chinese or Arabic') }}</label>
                    <input type="text" name="name_local" id="f-name_local" dir="auto" class="form-control @error('name_local') is-invalid @enderror" value="{{ old('name_local', $product?->name_local) }}" maxlength="255">
                    @error('name_local')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label" for="f-description">{{ __('Description') }}</label>
                    <textarea name="description" id="f-description" rows="2" class="form-control @error('description') is-invalid @enderror">{{ old('description', $product?->description) }}</textarea>
                    @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    @include('parties._select', ['name' => 'unit', 'label' => __('Unit'), 'options' => $units, 'value' => $product?->unit ?? 'pcs', 'required' => true])
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="f-hs_code">{{ __('HS code') }}</label>
                    <input type="text" name="hs_code" id="f-hs_code" dir="ltr" class="form-control @error('hs_code') is-invalid @enderror" value="{{ old('hs_code', $product?->hs_code) }}" maxlength="30">
                    @error('hs_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="app-docform__section">
            <legend class="app-docform__legend"><i class="bi bi-rulers" aria-hidden="true"></i>{{ __('Carton') }}</legend>
            <div class="row g-3" data-carton>
                <div class="col-md-4">
                    <label class="form-label" for="f-qty_per_carton">{{ __('Quantity per carton') }}</label>
                    <input type="number" name="qty_per_carton" id="f-qty_per_carton" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error('qty_per_carton') is-invalid @enderror" value="{{ $num('qty_per_carton') }}">
                    @error('qty_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8">
                    <span class="form-label d-block" id="f-carton-size">{{ __('Carton size (cm)') }}</span>
                    <div class="app-productform__size" role="group" aria-labelledby="f-carton-size">
                        @foreach(['carton_length_cm' => __('Length (cm)'), 'carton_width_cm' => __('Width (cm)'), 'carton_height_cm' => __('Height (cm)')] as $field => $label)
                            <div>
                                <label class="visually-hidden" for="f-{{ $field }}">{{ $label }}</label>
                                <input type="number" name="{{ $field }}" id="f-{{ $field }}" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error($field) is-invalid @enderror" value="{{ $num($field) }}" placeholder="{{ $label }}" data-carton-side>
                                @error($field)<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            @unless($loop->last)<span class="app-productform__times" aria-hidden="true">×</span>@endunless
                        @endforeach
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="f-cbm_per_carton">{{ __('CBM per carton') }}</label>
                    <input type="number" name="cbm_per_carton" id="f-cbm_per_carton" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error('cbm_per_carton') is-invalid @enderror" value="{{ $num('cbm_per_carton') }}" data-carton-cbm aria-describedby="f-cbm-help">
                    <div class="form-text" id="f-cbm-help">{{ __('Worked out from the size; you can change it.') }}</div>
                    @error('cbm_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="f-kg_per_carton">{{ __('Gross KG per carton') }}</label>
                    <input type="number" name="kg_per_carton" id="f-kg_per_carton" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error('kg_per_carton') is-invalid @enderror" value="{{ $num('kg_per_carton') }}">
                    @error('kg_per_carton')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
        </fieldset>

        <fieldset class="app-docform__section">
            <legend class="app-docform__legend"><i class="bi bi-tags" aria-hidden="true"></i>{{ __('Prices') }}</legend>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="f-sale_price">{{ __('Sale price') }}</label>
                    <input type="number" name="sale_price" id="f-sale_price" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error('sale_price') is-invalid @enderror" value="{{ $num('sale_price') }}">
                    @error('sale_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    @include('parties._select', ['name' => 'sale_currency', 'label' => __('Sale currency'), 'options' => $currencies, 'value' => $product?->sale_currency ?? ($product ? null : $defaults['sale_currency'])])
                </div>
                @if($canSeeSupplier)
                    <div class="col-12">
                        <div class="app-doc-internal">
                            <div class="app-doc-internal__label"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Usual supplier') }} · {{ __('Never printed on the PI.') }}</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    @include('parties._select', ['name' => 'supplier_id', 'label' => __('Usual supplier'), 'options' => $suppliers->mapWithKeys(fn ($s) => [$s->id => $s->code.' · '.$s->name])->all(), 'value' => $product?->supplier_id])
                                </div>
                                <div class="col-sm-6">
                                    <label class="form-label" for="f-supplier_price">{{ __('Supplier price') }}</label>
                                    <input type="number" name="supplier_price" id="f-supplier_price" dir="ltr" step="0.0001" min="0" inputmode="decimal" class="form-control @error('supplier_price') is-invalid @enderror" value="{{ $num('supplier_price') }}">
                                    @error('supplier_price')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                                <div class="col-sm-6">
                                    @include('parties._select', ['name' => 'supplier_currency', 'label' => __('Supplier currency'), 'options' => $currencies, 'value' => $product?->supplier_currency ?? ($product ? null : $defaults['supplier_currency'])])
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </fieldset>
    </div>

    <aside class="app-productform__aside">
        <fieldset class="app-docform__section">
            <legend class="app-docform__legend"><i class="bi bi-image" aria-hidden="true"></i>{{ __('Photo') }}</legend>
            <div class="app-productform__preview" data-photo-preview>
                @if($product?->photo)
                    <img src="{{ route('products.photo', $product) }}" alt="{{ __('Photo') }}" width="320" height="320">
                @else
                    <span class="app-product__nophoto" aria-hidden="true"><i class="bi bi-image"></i></span>
                @endif
            </div>
            <label class="form-label mt-3" for="f-photo">{{ $product?->photo ? __('Replace the photo') : __('Add photo') }}</label>
            <input type="file" name="photo" id="f-photo" accept=".jpg,.jpeg,.png,.webp" class="form-control @error('photo') is-invalid @enderror" aria-describedby="f-photo-help" data-photo-input>
            <div class="form-text" id="f-photo-help">{{ __('JPG, PNG or WebP, up to :size MB. A new photo replaces the old one.', ['size' => round($photoRules / 1024)]) }}</div>
            @error('photo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            @if($product?->photo && ! empty($removePhotoForm))
                <button type="submit" form="{{ $removePhotoForm }}" class="btn btn-sm btn-outline-danger mt-3"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Remove photo') }}</button>
            @endif
        </fieldset>

        <fieldset class="app-docform__section">
            <legend class="app-docform__legend"><i class="bi bi-toggle-on" aria-hidden="true"></i>{{ __('Status') }}</legend>
            <input type="hidden" name="active" value="0">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="f-active" @checked(old('active', $product?->active ?? true))>
                <label class="form-check-label" for="f-active">{{ __('Active') }}</label>
            </div>
        </fieldset>
    </aside>
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
    // The chosen photo is shown before it is saved.
    (() => {
        const input = document.querySelector('[data-photo-input]');
        const preview = document.querySelector('[data-photo-preview]');
        if (! input || ! preview) return;
        input.addEventListener('change', () => {
            const file = input.files && input.files[0];
            if (! file || ! file.type.startsWith('image/')) return;
            const img = document.createElement('img');
            img.alt = '';
            img.src = URL.createObjectURL(file);
            preview.replaceChildren(img);
        });
    })();
</script>
@endpush
