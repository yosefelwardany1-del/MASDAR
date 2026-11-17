@extends('layouts.app')

@section('title', __('Edit account'))

@section('content')
<x-page-header :title="__('Edit account')" :subtitle="$account->code.' — '.$account->displayName()" :back="route('accounting.index')" :back-label="__('Chart of accounts')" />
<form method="POST" action="{{ route('accounting.accounts.update', $account) }}" class="app-form">
    @csrf @method('PUT')
    @include('accounting.accounts._form')
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('accounting.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
