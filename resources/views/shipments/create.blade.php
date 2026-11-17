@extends('layouts.app')

@section('title', __('New shipment'))

@section('content')
<h1 class="h4 mb-3">{{ __('New shipment') }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('shipments.store') }}">
        @csrf
        @include('shipments._form', ['shipment' => null])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('shipments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
