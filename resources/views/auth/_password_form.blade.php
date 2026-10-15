<form method="POST" action="{{ route('login.post') }}" novalidate>
    @csrf
    <div class="mb-3">
        <label for="email" class="form-label">{{ __('E-mail') }}</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" dir="ltr"
               class="form-control @error('email') is-invalid @enderror" autocomplete="username" required autofocus>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label for="password" class="form-label">{{ __('Password') }}</label>
        <input id="password" type="password" name="password" dir="ltr" class="form-control" autocomplete="current-password" required>
    </div>
    <div class="form-check mb-3">
        <input class="form-check-input" type="checkbox" name="remember" id="remember" value="1">
        <label class="form-check-label" for="remember">{{ __('Remember me') }}</label>
    </div>
    <button type="submit" class="btn btn-primary w-100 py-2">{{ __('Sign in') }}</button>
</form>
