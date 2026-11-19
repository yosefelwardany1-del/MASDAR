{{-- B24: the error pages in MASDAR's own look, each with a way back. Standalone (no sidebar): an
     error page must render even when the app around it could not. --}}
<!DOCTYPE html>
@php
    try { $rtl = \App\Http\Middleware\SetUserLocale::isRtl(); } catch (\Throwable) { $rtl = app()->getLocale() === 'ar'; }
    $back = url()->previous();
    $back = $back && $back !== url()->current() ? $back : null;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    @vite([$rtl ? 'resources/css/app.css' : 'resources/css/app-ltr.css'])
</head>
<body class="app-auth">
<div class="app-auth__wrap">
    <aside class="app-auth__brand">
        <span class="d-inline-block bg-white rounded-3 p-2"><img src="{{ asset('images/masdar-logo.png') }}" alt="MASDAR TRADING" style="block-size:44px;inline-size:auto"></span>
    </aside>
    <main class="app-auth__form">
        <div class="app-auth__card">
            <p class="app-num text-muted mb-1">@yield('code')</p>
            <h1 class="h3 mb-3">@yield('title')</h1>
            <p class="mb-4">@yield('message')</p>
            <div class="d-flex flex-wrap gap-2">
                @if($back)<a href="{{ $back }}" class="btn btn-primary"><i class="bi bi-arrow-left" aria-hidden="true"></i> {{ __('Go back') }}</a>@endif
                <a href="{{ url('/') }}" class="btn btn-light"><i class="bi bi-house" aria-hidden="true"></i> {{ __('Home') }}</a>
            </div>
        </div>
    </main>
</div>
</body>
</html>
