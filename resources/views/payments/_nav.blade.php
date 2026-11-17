{{-- The three pages of the Payments screen. $active: payments, accounts or rates. --}}
<nav aria-label="{{ __('Payments') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach([
            'payments' => ['payments.index', 'bi-cash-coin', __('Payments')],
            'accounts' => ['money-accounts.index', 'bi-bank', __('Money accounts')],
            'rates' => ['fx-rates.index', 'bi-currency-exchange', __('FX rates')],
        ] as $key => [$route, $icon, $label])
            <li class="nav-item">
                <a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ route($route) }}" @if($active === $key) aria-current="page" @endif><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</a>
            </li>
        @endforeach
    </ul>
</nav>
