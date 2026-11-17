@extends('layouts.app')

@section('title', __('Edit user'))

@section('content')
<x-page-header :title="$user->name" :back="route('users.show', $user)" :back-label="$user->name" :subtitle="__('Edit user')">
    <x-slot:meta>@include('users._status', ['status' => $user->status])</x-slot:meta>
</x-page-header>

@if($user->isPending())
    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <div class="fw-semibold mb-1">{{ __('Awaiting approval') }}</div>
            {{ __('This person signed in with Google and has no role yet. Pick their roles and set the status to Active to let them in.') }}
        </div>
    </div>
@endif

<form method="POST" action="{{ route('users.update', $user) }}" class="app-form">
    @csrf @method('PUT')
    @include('users._form', ['user' => $user])
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
        <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>

@if($ssoEnabled || $ssoIdentities->isNotEmpty())
    <x-section :title="__('Google sign-in addresses')" icon="google" class="mt-4">
        <ul class="app-legs mb-3">
            @forelse($ssoIdentities as $identity)
                <li class="app-legs__row">
                    <span class="app-legs__main"><span class="app-ltr">{{ $identity->email }}</span></span>
                    @if($identity->email !== \App\Models\UserSsoIdentity::normalise($user->email))
                        <form method="POST" action="{{ route('users.sso-identities.destroy', [$user, $identity]) }}">@csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-link-45deg" aria-hidden="true"></i>{{ __('Unlink') }}</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="text-muted small">{{ __('None yet.') }}</li>
            @endforelse
        </ul>
        @if($ssoEnabled)
            <form method="POST" action="{{ route('users.sso-identities.store', $user) }}">
                @csrf
                <label class="form-label" for="sso-email">{{ __('Another Google address for this person') }}</label>
                <div class="d-flex gap-2">
                    <input type="email" name="email" id="sso-email" dir="ltr" class="form-control @error('sso_email') is-invalid @enderror">
                    <button class="btn btn-light">{{ __('Link') }}</button>
                </div>
                @error('sso_email')<div class="app-field-error">{{ $message }}</div>@enderror
            </form>
        @endif
    </x-section>
@endif
@endsection
