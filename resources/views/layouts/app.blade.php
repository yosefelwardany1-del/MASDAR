@php
    use App\Security\Screens;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    $user = auth()->user();
    $setting = \App\Models\Setting::current();
    $groupLabels = ['overview' => __('Overview'), 'crm' => __('Clients & partners'), 'sales' => __('Sales'), 'operations' => __('Operations'), 'finance' => __('Finance'), 'admin' => __('Administration')];
    $sections = collect(Screens::visibleFor($user))->groupBy('group');
    $link = fn (array $item) => Route::has($item['route']) ? route($item['route']) : '#';
    $isActive = fn (array $item) => request()->routeIs($item['route'], Str::beforeLast($item['route'], '.').'.*', ...($item['active'] ?? []));
@endphp
<!DOCTYPE html>
@php $rtl = \App\Http\Middleware\SetUserLocale::isRtl(); @endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Home')) · {{ config('app.name') }}</title>
    @vite([$rtl ? 'resources/css/app.css' : 'resources/css/app-ltr.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
<div class="app-overlay"></div>

<nav id="app-sidebar" aria-label="{{ __('Main menu') }}">
    <div class="app-brand">
        <span class="app-brand__logo" @unless($setting->logo) style="background:#fff;padding:4px" @endunless>@if($setting->logo)<img src="{{ route('settings.logo') }}" alt="">@else<img src="{{ asset('images/masdar-mark.png') }}" alt="MASDAR">@endif</span>
        <span class="app-brand__name">{{ app()->getLocale() === 'ar' ? $setting->company_name_ar : $setting->company_name }}</span>
    </div>

    <div class="app-nav">
        @foreach($sections as $group => $items)
            <div class="app-nav__group">{{ $groupLabels[$group] ?? __(Str::headline($group)) }}</div>
            @foreach($items as $item)
                <a href="{{ $link($item) }}" class="app-nav__link {{ $isActive($item) ? 'active' : '' }}">
                    <i class="bi {{ $item['icon'] }}"></i><span>{{ __($item['label']) }}</span>
                </a>
            @endforeach
        @endforeach
    </div>

    @if(Route::has('logout'))
        <div class="app-sidebar__foot">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="app-nav__link"><i class="bi bi-box-arrow-left"></i><span>{{ __('Sign out') }}</span></button>
            </form>
        </div>
    @endif
</nav>

<div id="app-main">
    <header id="app-topbar">
        <button type="button" class="app-iconbtn d-lg-none" data-app-sidebar-toggle aria-label="{{ __('Menu') }}"><i class="bi bi-list"></i></button>
        <div class="app-topbar__title">@yield('title', __('Home'))</div>

        <div class="ms-auto d-flex align-items-center gap-2">
            @stack('topbar')
            @include('partials.notification-bell')
            <div class="dropdown">
                <button class="btn btn-link text-decoration-none d-flex align-items-center gap-2 p-1" data-bs-toggle="dropdown" aria-expanded="false">
                    <x-avatar :name="$user->name" :src="$user->avatarUrl()" :size="34" />
                    <span class="d-none d-md-inline text-body fw-semibold">{{ $user->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="dropdown-item-text small text-muted"><span class="app-ltr">{{ $user->email }}</span></li>
                    @if(Route::has('profile'))
                        <li><a class="dropdown-item" href="{{ route('profile') }}"><i class="bi bi-person me-2"></i>{{ __('My profile') }}</a></li>
                    @endif
                    <li><hr class="dropdown-divider"></li>
                    <li class="dropdown-item-text small text-muted"><i class="bi bi-translate me-2"></i>{{ __('Language') }}</li>
                    @foreach(\App\Http\Middleware\SetUserLocale::LOCALES as $code => $name)
                        <li>
                            <form method="POST" action="{{ route('profile.locale') }}">@csrf @method('PUT')
                                <input type="hidden" name="locale" value="{{ $code }}">
                                <button type="submit" class="dropdown-item {{ app()->getLocale() === $code ? 'active' : '' }}" lang="{{ str_replace('_', '-', $code) }}">{{ $name }}</button>
                            </form>
                        </li>
                    @endforeach
                    @if(Route::has('logout'))
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <form method="POST" action="{{ route('logout') }}">@csrf
                                <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-left me-2"></i>{{ __('Sign out') }}</button>
                            </form>
                        </li>
                    @endif
                </ul>
            </div>
        </div>
    </header>

    <main class="app-content">
        @foreach(['success' => 'success', 'error' => 'danger', 'warning' => 'warning'] as $key => $tone)
            @if(session($key))
                <div class="alert alert-{{ $tone }} alert-dismissible fade show" role="alert">
                    {{ session($key) }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="{{ __('Close') }}"></button>
                </div>
            @endif
        @endforeach

        @yield('content')
    </main>
</div>

@stack('scripts')
</body>
</html>
