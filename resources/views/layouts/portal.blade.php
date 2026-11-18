{{-- The client portal's shell (spec §5.8): MASDAR's brand, the client's name, three places,
     sign out. Same Vite bundle as the app. --}}
<!DOCTYPE html>
@php $rtl = \App\Http\Middleware\SetUserLocale::isRtl(); $company = \App\Models\Setting::current()->company_name; @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ $company }}</title>
    @vite([$rtl ? 'resources/css/app.css' : 'resources/css/app-ltr.css', 'resources/js/app.js'])
</head>
<body class="app-portal">
<header class="app-portal__bar">
    <div class="app-portal__inner">
        <a href="{{ route('portal.home') }}" class="app-portal__brand"><img src="{{ asset('images/masdar-logo.png') }}" alt="{{ $company }}"></a>
        <nav class="app-portal__nav" aria-label="{{ __('Portal') }}">
            <a href="{{ route('portal.home') }}" @class(['is-active' => request()->routeIs('portal.home', 'portal.container')])>{{ __('My orders') }}</a>
            <a href="{{ route('portal.statement') }}" @class(['is-active' => request()->routeIs('portal.statement')])>{{ __('My statement') }}</a>
        </nav>
        <form method="POST" action="{{ route('logout') }}" class="ms-auto">@csrf<button class="btn btn-sm btn-light" aria-label="{{ __('Sign out') }}"><i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="d-none d-sm-inline">{{ __('Sign out') }}</span></button></form>
    </div>
</header>
<main class="app-portal__inner app-portal__main">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @yield('content')
</main>
<footer class="app-portal__inner app-portal__foot">{{ $company }}</footer>
</body>
</html>
