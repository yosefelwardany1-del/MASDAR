@extends('layouts.app')

@section('title', __('Transfer between accounts'))

@section('content')
<x-page-header :title="__('Transfer between accounts')" :back="route('payments.index')" :back-label="__('Payments')"
               :subtitle="__('Money moved between two of MASDAR\'s own accounts, often with an exchange on the way (for example Hong Kong USD to a mainland RMB account). It is recorded as two linked payments: out of one account, into the other.')" />

<form method="POST" action="{{ route('payments.transfer.store') }}" enctype="multipart/form-data" class="app-form">
    @csrf
    @include('configuration._form-errors')
    @if($approvalRequired)
        <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('The outgoing side waits for a manager\'s approval before it counts.') }}</div>
    @endif
    <p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

    <div class="app-sides">
        <x-section :title="__('Out of')" icon="box-arrow-up">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="tr-from">{{ __('Account') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <select name="from_account_id" id="tr-from" class="form-select @error('from_account_id') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" data-currency="{{ $a->currency }}" @selected((int) old('from_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                        @endforeach
                    </select>
                    @error('from_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="tr-date">{{ __('Date') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <input type="date" name="paid_on" id="tr-date" class="form-control @error('paid_on') is-invalid @enderror" value="{{ old('paid_on', now()->toDateString()) }}" required>
                    @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="tr-amount">{{ __('Amount sent') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <div class="input-group has-validation">
                        <input type="text" name="amount" id="tr-amount" inputmode="decimal" dir="ltr" class="form-control app-num @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                        <span class="input-group-text app-num" data-currency-of="tr-from">—</span>
                        @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-rate">{{ __('Rate to :book', ['book' => $book]) }}</label>
                    <input type="text" name="rate" id="tr-rate" inputmode="decimal" dir="ltr" class="form-control app-num @error('rate') is-invalid @enderror" value="{{ old('rate') }}" aria-describedby="tr-rate-help">
                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="tr-rate-help">{{ __('Leave empty to take the FX rate of the day.') }}</div>
                </div>
            </div>
        </x-section>

        <span class="app-sides__arrow" aria-hidden="true"><i class="bi {{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'bi-arrow-left' : 'bi-arrow-right' }}"></i></span>

        <x-section :title="__('Into')" icon="box-arrow-in-down">
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="tr-to">{{ __('Account') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <select name="to_account_id" id="tr-to" class="form-select @error('to_account_id') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" data-currency="{{ $a->currency }}" @selected((int) old('to_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                        @endforeach
                    </select>
                    @error('to_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="tr-in-date">{{ __('Arrival date') }}</label>
                    <input type="date" name="in_paid_on" id="tr-in-date" class="form-control @error('in_paid_on') is-invalid @enderror" value="{{ old('in_paid_on') }}" aria-describedby="tr-in-date-help">
                    @error('in_paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="tr-in-date-help">{{ __('Leave empty when it arrived the same day.') }}</div>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="tr-in-amount">{{ __('Amount received') }}</label>
                    <div class="input-group has-validation">
                        <input type="text" name="in_amount" id="tr-in-amount" inputmode="decimal" dir="ltr" class="form-control app-num @error('in_amount') is-invalid @enderror" value="{{ old('in_amount') }}" aria-describedby="tr-in-amount-help">
                        <span class="input-group-text app-num" data-currency-of="tr-to">—</span>
                        @error('in_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="form-text" id="tr-in-amount-help">{{ __('Needed when the two accounts are in different currencies: the amount that arrived.') }}</div>
                </div>
                <div class="col-12">
                    <label class="form-label" for="tr-in-rate">{{ __('Rate to :book', ['book' => $book]) }}</label>
                    <input type="text" name="in_rate" id="tr-in-rate" inputmode="decimal" dir="ltr" class="form-control app-num @error('in_rate') is-invalid @enderror" value="{{ old('in_rate') }}" aria-describedby="tr-in-rate-help">
                    @error('in_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="tr-in-rate-help">{{ __('Leave empty to take the FX rate of the day.') }}</div>
                </div>
            </div>
        </x-section>
    </div>

    <x-section :title="__('Reference, note and evidence')" icon="paperclip">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="tr-reference">{{ __('Reference') }}</label>
                <input type="text" name="reference" id="tr-reference" maxlength="120" dir="ltr" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference') }}" placeholder="{{ __('Bank or transaction reference') }}">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label" for="tr-files">{{ __('Evidence (bank slip or screenshot)') }}</label>
                <input type="file" name="files[]" id="tr-files" multiple class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.webp">
                @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="tr-note">{{ __('Note') }}</label>
                <textarea name="note" id="tr-note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </x-section>

    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>{{ __('Record the transfer') }}</button>
        <a href="{{ route('payments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
    // Show each chosen account's currency beside its amount.
    document.querySelectorAll('[data-currency-of]').forEach((unit) => {
        const account = document.getElementById(unit.dataset.currencyOf);
        if (!account) return;
        const show = () => { unit.textContent = account.selectedOptions[0]?.dataset.currency || '—'; };
        account.addEventListener('change', show);
        show();
    });
</script>
@endpush
