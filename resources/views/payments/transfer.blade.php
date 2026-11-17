@extends('layouts.app')

@section('title', __('Transfer between accounts'))

@section('content')
<h1 class="h4 mb-3">{{ __('Transfer between accounts') }}</h1>

<div class="app-card p-4">
    <p class="text-muted">{{ __('Money moved between two of MASDAR\'s own accounts, often with an exchange on the way (for example Hong Kong USD to a mainland RMB account). It is recorded as two linked payments: out of one account, into the other.') }}</p>
    @if($approvalRequired)
        <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>{{ __('The outgoing side waits for a manager\'s approval before it counts.') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger py-2">{{ __('Please correct the fields marked below.') }}</div>
    @endif

    <form method="POST" action="{{ route('payments.transfer.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="row g-4">
            <div class="col-lg-6">
                <h2 class="h6 mb-3"><i class="bi bi-box-arrow-up me-1"></i>{{ __('Out of') }}</h2>
                <div class="mb-3">
                    <label class="form-label">{{ __('Account') }} <span class="text-danger">*</span></label>
                    <select name="from_account_id" class="form-select @error('from_account_id') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" @selected((int) old('from_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                        @endforeach
                    </select>
                    @error('from_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                    <input type="date" name="paid_on" class="form-control @error('paid_on') is-invalid @enderror" value="{{ old('paid_on', now()->toDateString()) }}" required>
                    @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Amount sent') }} <span class="text-danger">*</span></label>
                    <input type="text" name="amount" inputmode="decimal" dir="ltr" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Rate to :book', ['book' => $book]) }}</label>
                    <input type="text" name="rate" inputmode="decimal" dir="ltr" class="form-control @error('rate') is-invalid @enderror" value="{{ old('rate') }}">
                    <div class="form-text">{{ __('Leave empty to take the FX rate of the day.') }}</div>
                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-lg-6">
                <h2 class="h6 mb-3"><i class="bi bi-box-arrow-in-down me-1"></i>{{ __('Into') }}</h2>
                <div class="mb-3">
                    <label class="form-label">{{ __('Account') }} <span class="text-danger">*</span></label>
                    <select name="to_account_id" class="form-select @error('to_account_id') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" @selected((int) old('to_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                        @endforeach
                    </select>
                    @error('to_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Arrival date') }}</label>
                    <input type="date" name="in_paid_on" class="form-control @error('in_paid_on') is-invalid @enderror" value="{{ old('in_paid_on') }}">
                    <div class="form-text">{{ __('Leave empty when it arrived the same day.') }}</div>
                    @error('in_paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Amount received') }}</label>
                    <input type="text" name="in_amount" inputmode="decimal" dir="ltr" class="form-control @error('in_amount') is-invalid @enderror" value="{{ old('in_amount') }}">
                    <div class="form-text">{{ __('Needed when the two accounts are in different currencies: the amount that arrived.') }}</div>
                    @error('in_amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Rate to :book', ['book' => $book]) }}</label>
                    <input type="text" name="in_rate" inputmode="decimal" dir="ltr" class="form-control @error('in_rate') is-invalid @enderror" value="{{ old('in_rate') }}">
                    <div class="form-text">{{ __('Leave empty to take the FX rate of the day.') }}</div>
                    @error('in_rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('Reference') }}</label>
                <input type="text" name="reference" maxlength="120" dir="ltr" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference') }}">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-6">
                <label class="form-label">{{ __('Evidence (bank slip or screenshot)') }}</label>
                <input type="file" name="files[]" multiple class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.webp">
                @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('Note') }}</label>
                <textarea name="note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Record the transfer') }}</button>
            <a href="{{ route('payments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
