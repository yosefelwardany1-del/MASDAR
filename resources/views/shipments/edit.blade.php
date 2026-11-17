@extends('layouts.app')

@section('title', __('Edit shipment'))

@section('content')
<h1 class="h4 mb-3"><span class="app-ltr">{{ $shipment->number }}</span></h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('shipments.update', $shipment) }}">
        @csrf @method('PUT')
        @include('shipments._form', ['shipment' => $shipment])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('shipments.show', $shipment) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
