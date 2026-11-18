{{-- Sub-navigation of Leave & attendance: $active = requests | balances | attendance. --}}
<nav aria-label="{{ __('Leave & attendance') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach([
            'requests' => [route('leave.index', ['tab' => 'requests']), __('Requests'), 'calendar-check'],
            'balances' => [route('leave.index', ['tab' => 'balances']), __('Balances'), 'pie-chart'],
            'attendance' => [route('attendance.index'), __('Attendance sheet'), 'grid-3x3'],
        ] as $key => [$href, $label, $icon])
            <li class="nav-item"><a class="nav-link {{ $active === $key ? 'active' : '' }}" href="{{ $href }}" @if($active === $key) aria-current="page" @endif><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>{{ $label }}</a></li>
        @endforeach
    </ul>
</nav>
