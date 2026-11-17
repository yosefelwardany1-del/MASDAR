@extends('layouts.app')

@section('title', __('Add a rate'))

@section('content')
<h1 class="h4 mb-3">{{ __('Add a rate') }}</h1>
<div class="app-card p-4">
    <p class="text-muted small">{{ __('Saving a rate for a currency and day that already has one replaces it.') }}</p>
    <form method="POST" action="{{ route('fx-rates.store') }}">
        @csrf
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">{{ __('Currency') }} <span class="text-danger">*</span></label>
                <select name="currency" class="form-select @error('currency') is-invalid @enderror" required>
                    @foreach($currencies as $code => $name)
                        <option value="{{ $code }}" @selected(old('currency', $presetCurrency) === $code)>{{ $code }} — {{ $name }}</option>
                    @endforeach
                </select>
                @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                <input type="date" name="rate_date" class="form-control @error('rate_date') is-invalid @enderror" value="{{ old('rate_date', now()->toDateString()) }}" required>
                @error('rate_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('Rate to :book', ['book' => $book]) }} <span class="text-danger">*</span></label>
                <input type="text" name="rate" inputmode="decimal" dir="ltr" class="form-control @error('rate') is-invalid @enderror" value="{{ old('rate') }}" required>
                <div class="form-text">{{ __(':book for one unit of the currency, up to 6 decimals.', ['book' => $book]) }}</div>
                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('fx-rates.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
