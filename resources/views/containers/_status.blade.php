{{-- A container's current status (its latest event), as a pill with an icon. Needs $status (code or null), $statusSort, $statuses (code => name), $arrivedSort. --}}
@if($status === null || $status === '')
    <span class="app-pill app-pill--skip"><i class="bi bi-dash-circle" aria-hidden="true"></i>{{ __('No status yet') }}</span>
@elseif((int) $statusSort >= $arrivedSort)
    <span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ $statuses[$status] ?? $status }}</span>
@else
    <span class="app-pill app-pill--info"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ $statuses[$status] ?? $status }}</span>
@endif
