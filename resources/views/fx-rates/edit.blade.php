@extends('layouts.app')

@section('title', __('Edit rate'))

@section('content')
<h1 class="h4 mb-3">{{ __('Edit rate') }}: {{ $rate->currency }} · <span class="app-num">{{ $rate->rate_date->toDateString() }}</span></h1>
<div class="app-card p-4">
    <p class="text-muted small">{{ __('Payments already recorded keep the rate they were made at.') }}</p>
    <form method="POST" action="{{ route('fx-rates.update', $rate) }}">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">{{ __('Rate to :book', ['book' => $book]) }} <span class="text-danger">*</span></label>
                <input type="text" name="rate" inputmode="decimal" dir="ltr" class="form-control @error('rate') is-invalid @enderror" value="{{ old('rate', rtrim(rtrim((string) $rate->rate, '0'), '.')) }}" required>
                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('currency')<div class="text-danger small">{{ $message }}</div>@enderror
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('fx-rates.index', ['currency' => $rate->currency]) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
