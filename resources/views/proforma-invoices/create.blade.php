@extends('layouts.app')

@section('title', __('New PI'))

@section('content')
<x-page-header :title="__('New PI')" :subtitle="__('Start a draft for the client. You add the lines next, then issue it.')"
               :back="route('proforma-invoices.index')" :back-label="__('Proforma invoices')" />

<form method="POST" action="{{ route('proforma-invoices.store') }}" class="app-card app-docform">
    @csrf
    @include('proforma-invoices._error-summary')
    @include('proforma-invoices._header-form', ['pi' => null])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create draft') }}</button>
        <a href="{{ route('proforma-invoices.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
