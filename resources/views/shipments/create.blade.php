@extends('layouts.app')

@section('title', __('New shipment'))

@section('content')
<x-page-header :title="__('New shipment')" :subtitle="__('A booking with a shipping company. Add its containers and book orders on it once it is saved.')"
               :back="route('shipments.index')" :back-label="__('All shipments')" />

@include('supplier-orders._form-errors')

<form method="POST" action="{{ route('shipments.store') }}" class="app-fu-form">
    @csrf
    @include('shipments._form', ['shipment' => null])
    <div class="app-fu-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('shipments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection

@if($errors->any())
    @push('scripts')
    <script>document.addEventListener('DOMContentLoaded', () => document.getElementById('form-errors')?.focus());</script>
    @endpush
@endif
