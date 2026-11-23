@extends('layouts.app')

@section('title', __('Add a rate'))

@section('content')
<x-page-header :title="__('Add a rate')" :back="route('fx-rates.index')" :back-label="__('FX rates')"
               :subtitle="__('Saving a rate for a currency and day that already has one replaces it.')" />
<form method="POST" action="{{ route('fx-rates.store') }}" class="app-form">
    @csrf
    @include('configuration._form-errors')
    <p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>
    <x-section :title="__('The rate')" icon="currency-exchange">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="fx-currency">{{ __('Currency') }}<span class="app-req" aria-hidden="true">*</span></label>
                <select name="currency" id="fx-currency" class="form-select @error('currency') is-invalid @enderror" required>
                    @foreach($currencies as $code => $name)
                        <option value="{{ $code }}" @selected(old('currency', $presetCurrency) === $code)>{{ $code }} — {{ $name }}</option>
                    @endforeach
                </select>
                @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fx-date">{{ __('Date') }}<span class="app-req" aria-hidden="true">*</span></label>
                <input type="date" max="{{ now()->toDateString() }}" name="rate_date" id="fx-date" class="form-control @error('rate_date') is-invalid @enderror" value="{{ old('rate_date', now()->toDateString()) }}" required>
                @error('rate_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label" for="fx-rate">{{ __('Rate to :book', ['book' => $book]) }}<span class="app-req" aria-hidden="true">*</span></label>
                <div class="input-group has-validation">
                    <input type="text" name="rate" id="fx-rate" inputmode="decimal" dir="ltr" class="form-control app-num @error('rate') is-invalid @enderror" value="{{ old('rate') }}" required aria-describedby="fx-rate-help">
                    <span class="input-group-text">{{ $book }}</span>
                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text" id="fx-rate-help">{{ __(':book for one unit of the currency, up to 6 decimals.', ['book' => $book]) }}</div>
            </div>
        </div>
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('fx-rates.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
