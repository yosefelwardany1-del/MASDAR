{{-- Shared by create and edit. $party is null on create. A kind's own fields are on the form
     only for a user who may write that kind (PartyController::mayWriteKind); another kind's
     section shows while its "also a" box is ticked. The server applies the same rules.
     In groups: who they are, where they are, each kind's own details, then notes and status. --}}
@php
    use App\Services\Parties\PartyKind;
    $sentKinds = old('kinds_sent') ? (array) old('kinds', []) : null;
    $isKind = fn (PartyKind $k) => $k === $kind || ($sentKinds !== null ? in_array($k->value, $sentKinds, true) : $flags[$k->column()]);
    $mayWrite = fn (PartyKind $k) => in_array($k->value, $editableKinds, true);
    $section = fn (PartyKind $k) => $mayWrite($k) ? ($isKind($k) ? '' : 'd-none') : null;
    $req = '<span class="text-danger" aria-hidden="true">*</span>';
@endphp
<input type="hidden" name="kinds_sent" value="1">

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-person-vcard" aria-hidden="true"></i>{{ __('Who they are') }}</legend>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="f-name">{{ __('Name (English)') }} {!! $req !!}</label>
            <input type="text" name="name" id="f-name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $party?->name) }}" maxlength="200" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="f-name_local">{{ __('Name in Chinese or Arabic') }}</label>
            <input type="text" name="name_local" id="f-name_local" dir="auto" class="form-control @error('name_local') is-invalid @enderror" value="{{ old('name_local', $party?->name_local) }}" maxlength="200">
            @error('name_local')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-12">
            <span class="form-label d-block" id="f-kinds-label">{{ __('Also a') }}</span>
            <div class="app-docform__choices" role="group" aria-labelledby="f-kinds-label">
                @foreach(PartyKind::cases() as $k)
                    @continue($k === $kind)
                    <div class="form-check app-docform__choice">
                        <input class="form-check-input" type="checkbox" name="kinds[]" value="{{ $k->value }}" id="kind-{{ $k->value }}" data-kind-toggle="{{ $k->value }}"
                               @checked($isKind($k)) @disabled(! $mayWrite($k))>
                        <label class="form-check-label {{ $mayWrite($k) ? '' : 'text-muted' }}" for="kind-{{ $k->value }}"><i class="bi {{ $k->icon() }}" aria-hidden="true"></i> {{ __($k->label()) }}</label>
                    </div>
                @endforeach
            </div>
            @error('kinds')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        </div>
    </div>
</fieldset>

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ __('Where they are') }}</legend>
    <div class="row g-3">
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
</fieldset>

@if($section(PartyKind::Client) !== null)
    <fieldset class="app-docform__section {{ $section(PartyKind::Client) }}" data-kind-section="client">
        <legend class="app-docform__legend"><i class="bi {{ PartyKind::Client->icon() }}" aria-hidden="true"></i>{{ __('Client details') }}</legend>
        <div class="row g-3">
            <div class="col-md-4">
                @if($ownClientsOnly)
                    <label class="form-label" for="f-account_manager_fixed">{{ __('Account manager') }}</label>
                    <input type="text" id="f-account_manager_fixed" class="form-control" value="{{ auth()->user()->name }}" disabled aria-describedby="f-account_manager_help">
                    <div class="form-text" id="f-account_manager_help">{{ __('You manage the clients you add.') }}</div>
                @else
                    @include('parties._select', ['name' => 'account_manager_id', 'label' => __('Account manager'), 'options' => $users->pluck('name', 'id')->all(), 'value' => $party?->account_manager_id ?? ($party ? null : auth()->id())])
                @endif
            </div>
            <div class="col-md-4">
                @include('parties._select', ['name' => 'source', 'label' => __('How they found us'), 'options' => $options['party_source'], 'value' => $party?->source])
            </div>
            <div class="col-md-4">
                @include('parties._select', ['name' => 'stage', 'label' => __('Stage'), 'options' => $options['client_stage'], 'value' => $party?->stage])
                <div class="form-text">{{ __('From "PI sent" on, the stage follows the client\'s PI, orders and containers.') }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="f-deposit_percent">{{ __('Deposit %') }}</label>
                <div class="input-group has-validation">
                    <input type="number" name="deposit_percent" id="f-deposit_percent" dir="ltr" min="0" max="100" step="0.01" inputmode="decimal" class="form-control @error('deposit_percent') is-invalid @enderror"
                           value="{{ old('deposit_percent', $party?->deposit_percent) }}" placeholder="{{ rtrim(rtrim((string) $defaultDeposit, '0'), '.') }}" aria-describedby="f-deposit_percent-help">
                    <span class="input-group-text">%</span>
                    @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text" id="f-deposit_percent-help">{{ __('Leave empty to use the business default (:percent%).', ['percent' => rtrim(rtrim((string) $defaultDeposit, '0'), '.')]) }}</div>
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
    <fieldset class="app-docform__section {{ $section(PartyKind::Supplier) }}" data-kind-section="supplier">
        <legend class="app-docform__legend"><i class="bi {{ PartyKind::Supplier->icon() }}" aria-hidden="true"></i>{{ __('Supplier details') }}</legend>
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
                    <div class="app-doc-internal">
                        <label class="form-label app-doc-internal__label" for="f-bank_details"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Bank or payment details') }}</label>
                        <textarea name="bank_details" id="f-bank_details" rows="3" dir="auto" class="form-control @error('bank_details') is-invalid @enderror">{{ old('bank_details', $party?->bank_details) }}</textarea>
                        @error('bank_details')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            @endif
        </div>
    </fieldset>
@endif

@if($section(PartyKind::Shipping) !== null)
    <fieldset class="app-docform__section {{ $section(PartyKind::Shipping) }}" data-kind-section="shipping">
        <legend class="app-docform__legend"><i class="bi {{ PartyKind::Shipping->icon() }}" aria-hidden="true"></i>{{ __('Shipping company details') }}</legend>
        <div class="row g-3">
            <div class="col-md-6">
                @include('parties._select', ['name' => 'shipping_kind', 'label' => __('Kind of shipping company'), 'options' => $options['shipping_company_kind'], 'value' => $party?->shipping_kind])
            </div>
        </div>
    </fieldset>
@endif

<fieldset class="app-docform__section">
    <legend class="app-docform__legend"><i class="bi bi-journal-text" aria-hidden="true"></i>{{ __('Notes and status') }}</legend>
    <div class="row g-3">
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
</fieldset>

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
