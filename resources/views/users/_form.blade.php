{{-- Shared by create and edit. $user is null on create. --}}
@php $held = $user ? $user->roles->pluck('name')->all() : []; @endphp
<div class="row g-3">
    <div class="col-md-6">
        <label class="form-label">{{ __('Full name') }} <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $user?->name) }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('E-mail') }} <span class="text-danger">*</span></label>
        <input type="email" name="email" dir="ltr" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user?->email) }}" required>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Phone') }}</label>
        <input type="text" name="phone" dir="ltr" class="form-control" value="{{ old('phone', $user?->phone) }}">
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Status') }} <span class="text-danger">*</span></label>
        <select name="status" class="form-select @error('status') is-invalid @enderror">
            <option value="active" @selected(old('status', $user?->status ?? 'active') === 'active')>{{ __('Active') }}</option>
            <option value="inactive" @selected(old('status', $user?->status) === 'inactive')>{{ __('Inactive') }}</option>
            @if($user?->isPending())
                <option value="pending" @selected(old('status', $user->status) === 'pending')>{{ __('Awaiting approval') }}</option>
            @endif
        </select>
        @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Password') }} @unless($user)<span class="text-danger">*</span>@endunless</label>
        <input type="password" name="password" dir="ltr" class="form-control @error('password') is-invalid @enderror" @unless($user) required @endunless minlength="8" autocomplete="new-password">
        @if($user)<div class="form-text">{{ __('Leave empty to keep the current password.') }}</div>@endif
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-6">
        <label class="form-label">{{ __('Confirm password') }}</label>
        <input type="password" name="password_confirmation" dir="ltr" class="form-control" minlength="8" autocomplete="new-password">
    </div>
    <div class="col-12">
        <label class="form-label">{{ __('Roles') }}</label>
        <div class="form-text mb-2">{{ __('A user may hold several roles; their permissions add up.') }}</div>
        @error('roles')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror
        <div class="row g-2">
            @foreach(\App\Security\Roles::ALL as $role)
                @php $assignable = in_array($role, $roles, true); @endphp
                <div class="col-md-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="roles[]" value="{{ $role }}" id="role-{{ $role }}"
                               @checked(in_array($role, old('roles', $held), true)) @disabled(! $assignable)>
                        <label class="form-check-label {{ $assignable ? '' : 'text-muted' }}" for="role-{{ $role }}">{{ \App\Security\Roles::label($role) }}</label>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
