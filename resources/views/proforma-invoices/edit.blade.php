@extends('layouts.app')

@section('title', __('Edit PI'))

@php $heading = new \Illuminate\Support\HtmlString('<span class="app-ltr">'.e($pi->reference()).'</span>'); @endphp

@section('content')
<x-page-header :title="$heading" :subtitle="__('Only a draft can change. The lines are edited on the PI\'s page.')"
               :back="route('proforma-invoices.show', $pi)" :back-label="__('Back to the PI')">
    <x-slot:meta>@include('proforma-invoices._status', ['pi' => $pi])</x-slot:meta>
</x-page-header>

<form method="POST" action="{{ route('proforma-invoices.update', $pi) }}" class="app-card app-docform">
    @csrf @method('PUT')
    @include('proforma-invoices._error-summary')
    @include('proforma-invoices._header-form', ['pi' => $pi])
    <div class="app-docform__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('proforma-invoices.show', $pi) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
