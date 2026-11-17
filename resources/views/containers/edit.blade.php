@extends('layouts.app')

@section('title', __('Edit container'))

@section('content')
<x-page-header :title="__('Edit container')" :back="route('containers.show', $container)" :back-label="$container->container_no ?: __('Container').' #'.$container->id">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-box-seam" aria-hidden="true"></i><span class="app-ltr">{{ $container->container_no ?: '#'.$container->id }}</span></span>
        <span class="app-pill"><i class="bi bi-truck" aria-hidden="true"></i><span class="app-ltr">{{ $container->shipment->number }}</span></span>
    </x-slot:meta>
</x-page-header>

@include('supplier-orders._form-errors')

<form method="POST" action="{{ route('containers.update', $container) }}" class="app-fu-form">
    @csrf @method('PUT')
    <x-section :title="__('Container')" icon="box-seam">
        @include('containers._form', ['container' => $container])
    </x-section>
    <div class="app-fu-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('containers.show', $container) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
