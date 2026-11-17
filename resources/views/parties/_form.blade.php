{{-- Shared by create and edit. $party is null on create. A kind's own fields are on the form
     only for a user who may write that kind (PartyController::mayWriteKind); another kind's
     section shows while its "also a" box is ticked. The server applies the same rules. --}}
@php
    use App\Services\Parties\PartyKind;
    $sentKinds = old('kinds_sent') ? (array) old('kinds', []) : null;
    $isKind = fn (PartyKind $k) => $k === $kind || ($sentKinds !== null ? in_array($k->value, $sentKinds, true) : $flags[$k->column()]);
    $mayWrite = fn (PartyKind $k) => in_array($k->value, $editableKinds, true);
    $section = fn (PartyKind $k) => $mayWrite($k) ? ($isKind($k) ? '' : 'd-none') : null;
@endphp
<input type="hidden" name="kinds_sent" value="1">
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label" for="f-name">{{ __('Name (English)') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" id="f-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $party?->name) }}" maxlength="200" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label" for="f-name_local">{{ __('Name in Chinese or Arabic') }}</label>
        <input type="text" name="name_local" id="f-name_local" class="form-control @error('name_local') is-invalid @enderror" value="{{ old('name_local', $party?->name_local) }}" maxlength="200">
        @error('name_local')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>

    <div class="col-12">
        <span class="form-label d-block">{{ __('Also a') }}</span>
        <div class="d-flex flex-wrap gap-4">
            @foreach(PartyKind::cases() as $k)
                @continue($k === $kind)
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="kinds[]" value="{{ $k->value }}" id="kind-{{ $k->value }}" data-kind-toggle="{{ $k->value }}"
                           @checked($isKind($k)) @disabled(! $mayWrite($k))>
                    <label class="form-check-label {{ $mayWrite($k) ? '' : 'text-muted' }}" for="kind-{{ $k->value }}">{{ __($k->label()) }}</label>
                </div>
            @endforeach
        </div>
        @error('kinds')<div class="text-danger small">{{ $message }}</div>@enderror
    </div>

    <div class="col-md-4">
        @include('parties._select', ['name' => 'country', 'label' => __('Country'), 'options' => $options['country'], 'value' => $party?->country])
    </div>
    <div class="col-md-4">
        <label class="form-label" for="f-city">{{ __('City') }}</label>
        <input type="text" name="city" id="f-city" class="form-control @error('city') is-invalid @enderror" value="{{ old('city', $party?->city) }}" maxlength="80">
        @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        @include('parties._select', ['name' => 'currency', 'label' => __('Default currency'), 'options' => $options['currency'], 'value' => $defaultCurrency])
    </div>
    <div class="col-12">
        <label class="form-label" for="f-address">{{ __('Address') }}</label>
        <textarea name="address" id="f-address" rows="2" class="form-control @error('address') is-invalid @enderror">{{ old('address', $party?->address) }}</textarea>
        @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
</div>

@if($section(PartyKind::Client) !== null)
    <fieldset class="mt-4 {{ $section(PartyKind::Client) }}" data-kind-section="client">
        <legend class="h6 border-bottom pb-2">{{ __('Client details') }}</legend>
        <div class="row g-3">
            <div class="col-md-4">
                @if($ownClientsOnly)
                    <label class="form-label">{{ __('Account manager') }}</label>
                    <input type="text" class="form-control" value="{{ auth()->user()->name }}" disabled>
                    <div class="form-text">{{ __('You manage the clients you add.') }}</div>
                @else
                    @include('parties._select', ['name' => 'account_manager_id', 'label' => __('Account manager'), 'options' => $users->pluck('name', 'id')->all(), 'value' => $party?->account_manager_id ?? ($party ? null : auth()->id())])
                @endif
            </div>
            <div class="col-md-4">
                @include('parties._select', ['name' => 'source', 'label' => __('How they found us'), 'options' => $options['party_source'], 'value' => $party?->source])
            </div>
            <div class="col-md-4">
                @include('parties._select', ['name' => 'stage', 'label' => __('Stage'), 'options' => $options['client_stage'], 'value' => $party?->stage])
            </div>
            <div class="col-md-4">
                <label class="form-label" for="f-deposit_percent">{{ __('Deposit %') }}</label>
                <input type="number" name="deposit_percent" id="f-deposit_percent" dir="ltr" min="0" max="100" step="0.01" class="form-control @error('deposit_percent') is-invalid @enderror"
                       value="{{ old('deposit_percent', $party?->deposit_percent) }}" placeholder="{{ rtrim(rtrim((string) $defaultDeposit, '0'), '.') }}">
                <div class="form-text">{{ __('Leave empty to use the business default (:percent%).', ['percent' => rtrim(rtrim((string) $defaultDeposit, '0'), '.')]) }}</div>
                @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label class="form-label" for="f-default_destination">{{ __('Shipped to (default destination)') }}</label>
                <input type="text" name="default_destination" id="f-default_destination" class="form-control @error('default_destination') is-invalid @enderror" value="{{ old('default_destination', $party?->default_destination) }}" maxlength="255">
                @error('default_destination')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </fieldset>
@endif

@if($section(PartyKind::Supplier) !== null)
    <fieldset class="mt-4 {{ $section(PartyKind::Supplier) }}" data-kind-section="supplier">
        <legend class="h6 border-bottom pb-2">{{ __('Supplier details') }}</legend>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="f-factory_address">{{ __('Factory or market booth address') }}</label>
                <textarea name="factory_address" id="f-factory_address" rows="2" class="form-control @error('factory_address') is-invalid @enderror">{{ old('factory_address', $party?->factory_address) }}</textarea>
                @error('factory_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="f-products_note">{{ __('What they make') }}</label>
                <textarea name="products_note" id="f-products_note" rows="2" class="form-control @error('products_note') is-invalid @enderror">{{ old('products_note', $party?->products_note) }}</textarea>
                @error('products_note')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @if($canSeeBank)
                <div class="col-12">
                    <label class="form-label" for="f-bank_details">{{ __('Bank or payment details') }}</label>
                    <textarea name="bank_details" id="f-bank_details" rows="3" dir="auto" class="form-control @error('bank_details') is-invalid @enderror">{{ old('bank_details', $party?->bank_details) }}</textarea>
                    @error('bank_details')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
    </fieldset>
@endif

@if($section(PartyKind::Shipping) !== null)
    <fieldset class="mt-4 {{ $section(PartyKind::Shipping) }}" data-kind-section="shipping">
        <legend class="h6 border-bottom pb-2">{{ __('Shipping company details') }}</legend>
        <div class="row g-3">
            <div class="col-md-6">
                @include('parties._select', ['name' => 'shipping_kind', 'label' => __('Kind of shipping company'), 'options' => $options['shipping_company_kind'], 'value' => $party?->shipping_kind])
            </div>
        </div>
    </fieldset>
@endif

<div class="row g-3 mt-1">
    <div class="col-12">
        <label class="form-label" for="f-notes">{{ __('Notes') }}</label>
        <textarea name="notes" id="f-notes" rows="3" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $party?->notes) }}</textarea>
        @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-12">
        <input type="hidden" name="active" value="0">
        <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="f-active" @checked(old('active', $party?->active ?? true))>
            <label class="form-check-label" for="f-active">{{ __('Active') }}</label>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.querySelectorAll('[data-kind-toggle]').forEach((box) => {
        const section = document.querySelector('[data-kind-section="' + box.dataset.kindToggle + '"]');
        if (section) {
            box.addEventListener('change', () => section.classList.toggle('d-none', ! box.checked));
        }
    });
</script>
@endpush
