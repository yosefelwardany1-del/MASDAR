@extends('layouts.app')

@section('title', __($kind->newLabel()))

@section('content')
<x-page-header :title="__($kind->newLabel())" :subtitle="__('A code is given when it is saved. Contacts, the CRM log and files are added on its page.')"
               :back="route($kind->routePrefix().'.index')" :back-label="__($kind->plural())" />

<form method="POST" action="{{ route($kind->routePrefix().'.store') }}" class="app-card app-docform">
    @csrf
    @include('proforma-invoices._error-summary', ['prefix' => 'f-'])
    @include('parties._form', ['party' => null])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route($kind->routePrefix().'.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
