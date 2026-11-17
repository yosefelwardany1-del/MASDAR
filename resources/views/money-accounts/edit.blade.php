@extends('layouts.app')

@section('title', __('Edit money account'))

@section('content')
<x-page-header :title="__('Edit money account')" :back="route('money-accounts.show', $account)" :back-label="$account->name" />
<form method="POST" action="{{ route('money-accounts.update', $account) }}" class="app-form">
    @csrf @method('PUT')
    @include('money-accounts._form')
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('money-accounts.show', $account) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
