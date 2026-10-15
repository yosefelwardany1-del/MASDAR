@php
    use App\Security\Screens;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\Str;

    $user = auth()->user();
    $setting = \App\Models\Setting::current();
    $groupLabels = ['overview' => __('Overview'), 'admin' => __('Administration')];
    $sections = collect(Screens::visibleFor($user))->groupBy('group');
    $link = fn (array $item) => Route::has($item['route']) ? route($item['route']) : '#';
    $isActive = fn (array $item) => request()->routeIs($item['route'], Str::beforeLast($item['route'], '.').'.*');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Home')) · {{ __('Alquds') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body>
<div class="aq-overlay"></div>

<nav id="aq-sidebar" aria-label="{{ __('Main menu') }}">
    <div class="aq-brand">
        <span class="aq-brand__logo">@if($setting->logo)<img src="{{ route('settings.logo') }}" alt="">@else<i class="bi bi-book"></i>@endif</span>
        <span class="aq-brand__name">{{ $setting->company_name_ar }}</span>
    </div>

    <div class="aq-nav">
        @foreach($sections as $group => $items)
            <div class="aq-nav__group">{{ $groupLabels[$group] ?? __(Str::headline($group)) }}</div>
            @foreach($items as $item)
                <a href="{{ $link($item) }}" class="aq-nav__link {{ $isActive($item) ? 'active' : '' }}">
                    <i class="bi {{ $item['icon'] }}"></i><span>{{ $item['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </div>

    @if(Route::has('logout'))
        <div class="aq-sidebar__foot">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="aq-nav__link"><i class="bi bi-box-arrow-left"></i><span>{{ __('Sign out') }}</span></button>
            </form>
        </div>
    @endif
</nav>

<div id="aq-main">
    <header id="aq-topbar">
        <button type="button" class="aq-iconbtn d-lg-none" data-aq-sidebar-toggle aria-label="{{ __('Menu') }}"><i class="bi bi-list"></i></button>
        <div class="aq-topbar__title">@yield('title', __('Home'))</div>

        <div class="ms-auto d-flex align-items-center gap-2">
            @stack('topbar')
            @include('partials.notification-bell')
            <div class="dropdown">
                <button class="btn btn-link text-decoration-none d-flex align-items-center gap-2 p-1" data-bs-toggle="dropdown" aria-expanded="false">
                    <x-avatar :name="$user->name" :src="$user->avatarUrl()" :size="34" />
                    <span class="d-none d-md-inline text-body fw-semibold">{{ $user->name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="dropdown-item-text small text-muted"><span class="aq-ltr">{{ $user->email }}</span></li>
                    @if(Route::has('profile'))
                        <li><a class="dropdown-item" href="{{ route('profile') }}"><i class="bi bi-person me-2"></i>{{ __('My profile') }}</a></li>
                    @endif
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

    <main class="aq-content">
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
