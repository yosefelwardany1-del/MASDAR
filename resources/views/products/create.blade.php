@extends('layouts.app')

@section('title', __('New product'))

@section('content')
<x-page-header :title="__('New product')" :subtitle="__('A code is given when it is saved. Its photo, carton and prices fill in every PI line made from it.')"
               :back="route('products.index')" :back-label="__('Products')" />

<form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="app-card app-docform app-docform--product">
    @csrf
    @include('proforma-invoices._error-summary', ['prefix' => 'f-'])
    @include('products._form', ['product' => null])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('products.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
