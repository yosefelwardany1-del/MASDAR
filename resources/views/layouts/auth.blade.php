{{-- The sign-in shell (login, the pending page). Brand panel in navy, a calm form column
     with one job. Same Vite bundle as the app, so nothing loads from a CDN. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title', __('Sign in')) · {{ __('Alquds') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="aq-auth">
<div class="aq-auth__wrap">
    <aside class="aq-auth__brand">
        <div class="d-flex align-items-center gap-2">
            <span class="aq-brand__logo"><i class="bi bi-book"></i></span>
            <span class="fw-bold fs-5">{{ __('Alquds') }}</span>
        </div>
        <div class="aq-auth__pitch d-none d-lg-block">
            <h1 class="h2 text-white mb-3">{{ __('Publishing, printing and shipping — in one place.') }}</h1>
            <p class="mb-0 opacity-75">{{ __('Stock, orders, containers and accounts, recorded once and never retyped.') }}</p>
        </div>
    </aside>
    <main class="aq-auth__form">
        <div class="aq-auth__card">
            @yield('content')
        </div>
    </main>
</div>
</body>
</html>
