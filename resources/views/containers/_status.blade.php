{{-- A container's current status (its latest event). Needs $status (code or null), $statusSort, $statuses (code => name), $arrivedSort. --}}
@if($status === null || $status === '')
    <span class="app-pill app-pill--skip">{{ __('No status yet') }}</span>
@else
    <span class="app-pill {{ (int) $statusSort >= $arrivedSort ? 'app-pill--done' : '' }}">{{ $statuses[$status] ?? $status }}</span>
@endif
