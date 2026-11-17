@extends('layouts.app')

@section('title', __('New PI'))

@section('content')
<h1 class="h4 mb-3">{{ __('New PI') }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('proforma-invoices.store') }}">
        @csrf
        @include('proforma-invoices._header-form', ['pi' => null])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create draft') }}</button>
            <a href="{{ route('proforma-invoices.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
