{{-- The two views of profit and their date filter. $active: orders | containers. --}}
<nav class="app-chips" aria-label="{{ __('Profit') }}">
    <a class="app-chip {{ $active === 'orders' ? 'is-active' : '' }}" href="{{ route('profit.index', ['from' => $from, 'to' => $to]) }}" @if($active === 'orders') aria-current="page" @endif><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('By sales order') }}</a>
    <a class="app-chip {{ $active === 'containers' ? 'is-active' : '' }}" href="{{ route('profit.containers', ['from' => $from, 'to' => $to]) }}" @if($active === 'containers') aria-current="page" @endif><i class="bi bi-boxes" aria-hidden="true"></i>{{ __('By container') }}</a>
</nav>
