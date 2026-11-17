{{-- Shared by create and edit. $user is null on create. --}}
@php $held = $user ? $user->roles->pluck('name')->all() : []; @endphp
@include('configuration._form-errors')
<p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

<x-section :title="__('Person')" icon="person">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="u-name">{{ __('Full name') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="text" name="name" id="u-name" autocomplete="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user?->name) }}" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="u-email">{{ __('E-mail') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input type="email" name="email" id="u-email" dir="ltr" autocomplete="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user?->email) }}" required>
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-6">
            <label class="form-label" for="u-phone">{{ __('Phone') }}</label>
            <input type="tel" name="phone" id="u-phone" dir="ltr" autocomplete="tel" class="form-control" value="{{ old('phone', $user?->phone) }}">
        </div>
    </div>
</x-section>

<x-section :title="__('Sign-in')" icon="key">
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="u-status">{{ __('Status') }}<span class="app-req" aria-hidden="true">*</span></label>
            <select name="status" id="u-status" class="form-select @error('status') is-invalid @enderror">
                <option value="active" @selected(old('status', $user?->status ?? 'active') === 'active')>{{ __('Active') }}</option>
                <option value="inactive" @selected(old('status', $user?->status) === 'inactive')>{{ __('Inactive') }}</option>
                @if($user?->isPending())
                    <option value="pending" @selected(old('status', $user->status) === 'pending')>{{ __('Awaiting approval') }}</option>
                @endif
            </select>
            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="u-password">{{ __('Password') }}@unless($user)<span class="app-req" aria-hidden="true">*</span>@endunless</label>
            <input type="password" name="password" id="u-password" dir="ltr" class="form-control @error('password') is-invalid @enderror" @unless($user) required @endunless minlength="8" autocomplete="new-password" aria-describedby="u-password-help">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="u-password-help">{{ $user ? __('Leave empty to keep the current password.') : __('At least 8 characters.') }}</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="u-password2">{{ __('Confirm password') }}</label>
            <input type="password" name="password_confirmation" id="u-password2" dir="ltr" class="form-control" minlength="8" autocomplete="new-password">
        </div>
    </div>
</x-section>

<x-section :title="__('Roles')" icon="shield-lock" :subtitle="__('A user may hold several roles; their permissions add up.')">
    @error('roles')<div class="alert alert-danger" role="alert"><span class="app-field-error text-reset">{{ $message }}</span></div>@enderror
    <div class="app-choices" role="group" aria-label="{{ __('Roles') }}">
        @foreach(\App\Security\Roles::ALL as $role)
            @php $assignable = in_array($role, $roles, true); @endphp
            <label class="app-choice" for="role-{{ $role }}">
                <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role }}" id="role-{{ $role }}"
                       @checked(in_array($role, old('roles', $held), true)) @disabled(! $assignable)>
                <span class="app-choice__text">
                    <span>{{ \App\Security\Roles::label($role) }}</span>
                    @unless($assignable)<span class="app-choice__sub"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('You cannot grant this role') }}</span>@endunless
                </span>
            </label>
        @endforeach
    </div>
</x-section>
