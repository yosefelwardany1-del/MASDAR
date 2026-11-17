{{-- The three pages of the Payments screen. $active: payments, accounts or rates. --}}
<ul class="nav nav-pills gap-1 mb-3">
    <li class="nav-item"><a class="nav-link {{ $active === 'payments' ? 'active' : '' }}" href="{{ route('payments.index') }}"><i class="bi bi-cash-coin me-1"></i>{{ __('Payments') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ $active === 'accounts' ? 'active' : '' }}" href="{{ route('money-accounts.index') }}"><i class="bi bi-bank me-1"></i>{{ __('Money accounts') }}</a></li>
    <li class="nav-item"><a class="nav-link {{ $active === 'rates' ? 'active' : '' }}" href="{{ route('fx-rates.index') }}"><i class="bi bi-currency-exchange me-1"></i>{{ __('FX rates') }}</a></li>
</ul>
