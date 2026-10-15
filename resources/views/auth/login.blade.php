@extends('layouts.auth')

@section('title', __('Sign in'))

@section('content')
    <h2 class="h3 mb-1">{{ __('Sign in') }}</h2>
    <p class="text-muted mb-4">{{ __('Welcome. Sign in to continue.') }}</p>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @error('sso')<div class="alert alert-danger">{{ $message }}</div>@enderror

    @if($googleSignIn)
        <a href="{{ route('sso.google') }}" class="btn btn-outline-secondary w-100 d-flex align-items-center justify-content-center gap-2 py-2 mb-3">
            <span style="inline-size:18px;block-size:18px;display:inline-flex;">@include('auth._google_g')</span>
            <span>{{ __('Continue with Google') }}</span>
        </a>
    @endif

    @if($passwordsRetired)
        <details class="mt-2" @if($errors->has('email')) open @endif>
            <summary class="text-muted small mb-3">{{ __('Emergency sign-in with a password') }}</summary>
            @include('auth._password_form')
        </details>
    @else
        @if($googleSignIn)
            <div class="text-center text-muted small my-3">{{ __('or with a password') }}</div>
        @endif
        @include('auth._password_form')
    @endif
@endsection
