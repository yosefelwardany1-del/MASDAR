@extends('layouts.app')

@section('title', __('New account'))

@section('content')
<x-page-header :title="__('New account')" :back="route('accounting.index')" :back-label="__('Chart of accounts')" />
<form method="POST" action="{{ route('accounting.accounts.store') }}" class="app-form">
    @csrf
    @include('accounting.accounts._form')
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('accounting.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
