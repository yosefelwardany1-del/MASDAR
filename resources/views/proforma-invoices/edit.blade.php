@extends('layouts.app')

@section('title', __('Edit PI'))

@section('content')
<h1 class="h4 mb-3"><span class="app-ltr">{{ $pi->reference() }}</span></h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('proforma-invoices.update', $pi) }}">
        @csrf @method('PUT')
        @include('proforma-invoices._header-form', ['pi' => $pi])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('proforma-invoices.show', $pi) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
