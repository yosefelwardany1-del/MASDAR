@extends('layouts.app')

@section('title', __('My profile'))

@section('content')
<div class="row g-4">
    <div class="col-lg-6">
        <div class="app-card p-4">
            <h2 class="h5 mb-4"><i class="bi bi-person me-2"></i>{{ __('Profile information') }}</h2>
            {{-- The photo form sits ABOVE the details form: a <form> inside a <form> is
                 invalid HTML and the browser drops the inner one. --}}
            <div class="text-center mb-4">
                <x-image-upload :src="$user->avatarUrl()" field="photo" :name="$user->name"
                                :action="route('profile.photo.store')" :remove="route('profile.photo.destroy')"
                                :size="96" shape="circle" :label="__('Add photo')" />
                <div class="mt-2 fw-semibold">{{ $user->name }}</div>
                <div class="text-muted small"><span class="app-ltr">{{ $user->email }}</span></div>
            </div>
            <form action="{{ route('profile.update') }}" method="POST">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label">{{ __('Full name') }} <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user->name) }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('E-mail') }} <span class="text-danger">*</span></label>
                    <input type="email" name="email" dir="ltr" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Phone') }}</label>
                    <input type="text" name="phone" dir="ltr" class="form-control" value="{{ old('phone', $user->phone) }}">
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Roles') }}</label>
                    <input type="text" class="form-control" disabled
                           value="{{ $user->roles->pluck('name')->map(fn ($r) => \App\Security\Roles::label($r))->join('، ') ?: __('No role assigned') }}">
                </div>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>{{ __('Save') }}</button>
            </form>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="app-card p-4">
            <h2 class="h5 mb-4"><i class="bi bi-shield-lock me-2"></i>{{ __('Change password') }}</h2>
            <form action="{{ route('profile.password') }}" method="POST">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="form-label">{{ __('Current password') }} <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" dir="ltr" class="form-control @error('current_password') is-invalid @enderror" required>
                    @error('current_password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('New password') }} <span class="text-danger">*</span></label>
                    <input type="password" name="password" dir="ltr" class="form-control @error('password') is-invalid @enderror" required minlength="8">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ __('Confirm new password') }} <span class="text-danger">*</span></label>
                    <input type="password" name="password_confirmation" dir="ltr" class="form-control" required minlength="8">
                </div>
                <button type="submit" class="btn btn-outline-primary"><i class="bi bi-key me-1"></i>{{ __('Change password') }}</button>
            </form>
        </div>

        <div class="app-card p-4 mt-4">
            <h2 class="h5 mb-3"><i class="bi bi-info-circle me-2"></i>{{ __('Account') }}</h2>
            <dl class="row mb-0 small">
                <dt class="col-5 text-muted fw-normal">{{ __('Member since') }}</dt><dd class="col-7">{{ $user->created_at->translatedFormat('j F Y') }}</dd>
                <dt class="col-5 text-muted fw-normal">{{ __('Last sign-in') }}</dt><dd class="col-7">{{ $user->last_login?->diffForHumans() ?? '—' }}</dd>
            </dl>
        </div>
    </div>
</div>
@endsection
