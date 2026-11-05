@extends('layouts.auth')

@section('title', __('Waiting for approval'))

@section('content')
    <h2 class="h3 mb-2">{{ __('Waiting for approval') }}</h2>
    <p class="text-muted">{{ __('Your account has been created and is waiting for an administrator to give it a role.') }}</p>
    <p class="mb-4"><span class="app-ltr fw-semibold">{{ $email }}</span></p>
    <p class="text-muted small mb-4">{{ __('Sign in again once you have been told it is ready.') }}</p>
    <a href="{{ route('login') }}" class="btn btn-light">{{ __('Back to sign-in') }}</a>
@endsection
