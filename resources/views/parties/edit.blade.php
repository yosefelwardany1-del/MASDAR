@extends('layouts.app')

@section('title', $party->code.' · '.$party->name)

@section('content')
<x-page-header :title="$party->name" :back="route($kind->routePrefix().'.show', $party)" :back-label="$party->code.' · '.$party->name">
    <x-slot:meta><span class="app-doc-code">{{ $party->code }}</span></x-slot:meta>
</x-page-header>

<form method="POST" action="{{ route($kind->routePrefix().'.update', $party) }}" class="app-card app-docform">
    @csrf @method('PUT')
    @include('proforma-invoices._error-summary', ['prefix' => 'f-'])
    @include('parties._form')
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route($kind->routePrefix().'.show', $party) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
