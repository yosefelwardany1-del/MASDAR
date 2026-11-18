@extends('layouts.app')

@section('title', __('New invoice'))

@section('content')
<x-page-header :title="__('New invoice')" :back="route('service-invoices.index')" :back-label="__('Service invoices')" :subtitle="__('Saved as a draft: check it, then a manager issues it.')" />
<form method="POST" action="{{ route('service-invoices.store') }}" class="app-form">
    @csrf
    @error('invoice')<div class="alert alert-danger">{{ $message }}</div>@enderror
    <x-section :title="__('The invoice')" icon="receipt">@include('service-invoices._header', ['invoice' => null])</x-section>
    <x-section :title="__('First line')" icon="list-ul" class="mt-3">
        <div class="row g-2">
            <div class="col-md-3"><label class="form-label" for="line_category">{{ __('For') }}</label><select id="line_category" name="line_category" class="form-select">@foreach($categories as $code => $label)<option value="{{ $code }}">{{ $label }}</option>@endforeach</select></div>
            <div class="col-md-6"><label class="form-label" for="line_description">{{ __('Description') }}</label><input id="line_description" name="line_description" value="{{ old('line_description') }}" class="form-control" dir="auto"></div>
            <div class="col-md-3"><label class="form-label" for="line_amount">{{ __('Amount') }}</label><input id="line_amount" name="line_amount" inputmode="decimal" value="{{ old('line_amount') }}" class="form-control"></div>
        </div>
    </x-section>
    <div class="mt-3"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save the draft') }}</button></div>
</form>
@endsection
