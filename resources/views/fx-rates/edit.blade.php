@extends('layouts.app')

@section('title', __('Edit rate'))

@section('content')
<x-page-header :title="__('Edit rate').': '.$rate->currency.' · '.$rate->rate_date->toDateString()" :back="route('fx-rates.index', ['currency' => $rate->currency])" :back-label="__('FX rates')"
               :subtitle="__('Payments already recorded keep the rate they were made at.')" />
<form method="POST" action="{{ route('fx-rates.update', $rate) }}" class="app-form">
    @csrf @method('PUT')
    @include('configuration._form-errors')
    <x-section :title="__('The rate')" icon="currency-exchange">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label" for="fx-rate">{{ __('Rate to :book', ['book' => $book]) }}<span class="app-req" aria-hidden="true">*</span></label>
                <div class="input-group has-validation">
                    <span class="input-group-text">1 {{ $rate->currency }} =</span>
                    <input type="text" name="rate" id="fx-rate" inputmode="decimal" dir="ltr" class="form-control app-num @error('rate') is-invalid @enderror" value="{{ old('rate', rtrim(rtrim((string) $rate->rate, '0'), '.')) }}" required>
                    <span class="input-group-text">{{ $book }}</span>
                    @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @error('currency')<div class="app-field-error">{{ $message }}</div>@enderror
            </div>
        </div>
    </x-section>
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('fx-rates.index', ['currency' => $rate->currency]) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
