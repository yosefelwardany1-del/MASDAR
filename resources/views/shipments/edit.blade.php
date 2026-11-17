@extends('layouts.app')

@section('title', __('Edit shipment'))

@section('content')
<x-page-header :title="__('Edit shipment')" :back="route('shipments.show', $shipment)" :back-label="$shipment->number">
    <x-slot:meta><span class="app-pill"><i class="bi bi-truck" aria-hidden="true"></i><span class="app-ltr">{{ $shipment->number }}</span></span></x-slot:meta>
</x-page-header>

@include('supplier-orders._form-errors')

<form method="POST" action="{{ route('shipments.update', $shipment) }}" class="app-fu-form">
    @csrf @method('PUT')
    @include('shipments._form', ['shipment' => $shipment])
    <div class="app-fu-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('shipments.show', $shipment) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection

@if($errors->any())
    @push('scripts')
    <script>document.addEventListener('DOMContentLoaded', () => document.getElementById('form-errors')?.focus());</script>
    @endpush
@endif
