@extends('layouts.app')

@section('title', __('New user'))

@section('content')
<x-page-header :title="__('New user')" :back="route('users.index')" :back-label="__('Users')" />
<form method="POST" action="{{ route('users.store') }}" class="app-form">
    @csrf
    @include('users._form', ['user' => null])
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('Create') }}</button>
        <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
