@extends('layouts.app')

@section('title', __('My profile'))

@section('content')
<x-page-header :title="__('My profile')" :subtitle="__('Your name, contact details and password.')" />
<div class="row g-4">
    <div class="col-lg-7">
        <div class="app-form">
            <x-section :title="__('Profile information')" icon="person">
                {{-- The photo form sits ABOVE the details form: a <form> inside a <form> is
                     invalid HTML and the browser drops the inner one. --}}
                <div class="app-person mb-4">
                    <x-image-upload :src="$user->avatarUrl()" field="photo" :name="$user->name"
                                    :action="route('profile.photo.store')" :remove="route('profile.photo.destroy')"
                                    :size="80" shape="circle" :label="__('Add photo')" />
                    <div>
                        <div class="app-person__name">{{ $user->name }}</div>
                        <div class="app-person__sub"><span class="app-ltr">{{ $user->email }}</span></div>
                        @if($user->roles->isNotEmpty())
                            <div class="app-rolelist mt-2">@foreach($user->roles as $r)<span class="app-pill">{{ \App\Security\Roles::label($r->name) }}</span>@endforeach</div>
                        @endif
                    </div>
                </div>
                <form action="{{ route('profile.update') }}" method="POST">
                    @csrf @method('PUT')
                    <p class="app-form__legend mb-3"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="p-name">{{ __('Full name') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="text" name="name" id="p-name" autocomplete="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p-email">{{ __('E-mail') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="email" name="email" id="p-email" dir="ltr" autocomplete="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p-phone">{{ __('Phone') }}</label>
                            <input type="tel" name="phone" id="p-phone" dir="ltr" autocomplete="tel" class="form-control" value="{{ old('phone', $user->phone) }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p-roles">{{ __('Roles') }}</label>
                            <input type="text" id="p-roles" class="form-control" disabled aria-describedby="p-roles-help"
                                   value="{{ $user->roles->pluck('name')->map(fn ($r) => \App\Security\Roles::label($r))->join('، ') ?: __('No role assigned') }}">
                            <div class="form-text" id="p-roles-help">{{ __('Roles are given by a manager.') }}</div>
                        </div>
                    </div>
                    <div class="mt-4"><button type="submit" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
                </form>
            </x-section>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="app-panel-stack">
            <form action="{{ route('profile.password') }}" method="POST" class="app-form">
                @csrf @method('PUT')
                <x-section :title="__('Change password')" icon="shield-lock">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="p-current">{{ __('Current password') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="password" name="current_password" id="p-current" dir="ltr" autocomplete="current-password" class="form-control @error('current_password') is-invalid @enderror" required>
                            @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="p-new">{{ __('New password') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="password" name="password" id="p-new" dir="ltr" autocomplete="new-password" class="form-control @error('password') is-invalid @enderror" required minlength="8" aria-describedby="p-new-help">
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="p-new-help">{{ __('At least 8 characters.') }}</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="p-new2">{{ __('Confirm new password') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="password" name="password_confirmation" id="p-new2" dir="ltr" autocomplete="new-password" class="form-control" required minlength="8">
                        </div>
                    </div>
                    <div class="mt-4"><button type="submit" class="btn btn-outline-primary"><i class="bi bi-key" aria-hidden="true"></i>{{ __('Change password') }}</button></div>
                </x-section>
            </form>

            <x-section :title="__('Account')" icon="info-circle">
                <x-detail-list :cols="2" :items="[
                    __('Member since') => $user->created_at->translatedFormat('j F Y'),
                    __('Last sign-in') => $user->last_login?->diffForHumans(),
                ]" />
            </x-section>
        </div>
    </div>
</div>
@endsection
