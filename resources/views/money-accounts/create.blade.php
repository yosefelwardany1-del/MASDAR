@extends('layouts.app')

@section('title', __('New money account'))

@section('content')
<x-page-header :title="__('New money account')" :back="route('money-accounts.index')" :back-label="__('Money accounts')" />
<form method="POST" action="{{ route('money-accounts.store') }}" class="app-form">
    @csrf
    @include('money-accounts._form')
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('money-accounts.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
