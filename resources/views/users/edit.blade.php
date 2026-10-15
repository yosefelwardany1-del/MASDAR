@extends('layouts.app')

@section('title', __('Edit user'))

@section('content')
<h1 class="h4 mb-3">{{ $user->name }}</h1>

@if($user->isPending())
    <div class="alert alert-warning">
        <div class="fw-semibold mb-1">{{ __('Awaiting approval') }}</div>
        {{ __('This person signed in with Google and has no role yet. Pick their roles and set the status to Active to let them in.') }}
    </div>
@endif

<div class="aq-card p-4">
    <form method="POST" action="{{ route('users.update', $user) }}">
        @csrf @method('PUT')
        @include('users._form', ['user' => $user])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>

@if($ssoEnabled || $ssoIdentities->isNotEmpty())
    <div class="aq-card p-4 mt-4">
        <h2 class="h6 mb-3">{{ __('Google sign-in addresses') }}</h2>
        <ul class="list-unstyled mb-3">
            @forelse($ssoIdentities as $identity)
                <li class="d-flex justify-content-between align-items-center py-1">
                    <span class="aq-ltr">{{ $identity->email }}</span>
                    @if($identity->email !== \App\Models\UserSsoIdentity::normalise($user->email))
                        <form method="POST" action="{{ route('users.sso-identities.destroy', [$user, $identity]) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger">{{ __('Unlink') }}</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="text-muted small">{{ __('None yet.') }}</li>
            @endforelse
        </ul>
        @if($ssoEnabled)
            <form method="POST" action="{{ route('users.sso-identities.store', $user) }}" class="d-flex gap-2">
                @csrf
                <input type="email" name="email" dir="ltr" class="form-control @error('sso_email') is-invalid @enderror" placeholder="{{ __('Another Google address for this person') }}">
                <button class="btn btn-light">{{ __('Link') }}</button>
            </form>
            @error('sso_email')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
        @endif
    </div>
@endif
@endsection
