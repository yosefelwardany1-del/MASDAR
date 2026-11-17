{{-- A fill gauge. Needs $percent (numeric string or null), $loaded, $capacity (numeric strings or null), $unit; optional $compact. --}}
@php
    $pct = $percent === null ? null : (int) $percent;
    $tone = $pct === null ? 'bg-secondary' : ($pct > 100 ? 'bg-danger' : ($pct >= 90 ? 'bg-warning' : 'bg-success'));
@endphp
@if(! empty($compact))
    @if($pct === null)
        <span class="text-muted">—</span>
    @else
        <div class="d-flex align-items-center gap-2" style="min-inline-size:110px">
            <div class="progress flex-grow-1" style="block-size:6px" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar {{ $tone }}" style="inline-size: {{ min($pct, 100) }}%"></div>
            </div>
            <span class="app-num {{ $pct > 100 ? 'text-danger fw-semibold' : '' }}">{{ $pct }}%</span>
        </div>
    @endif
@else
    <div class="mb-3">
        <div class="d-flex justify-content-between small mb-1">
            <span>{{ $unit }}</span>
            <span><span class="app-num">{{ \App\Services\Shipping\Num::show($loaded) }}</span>
                @if($capacity !== null) / <span class="app-num">{{ \App\Services\Shipping\Num::show($capacity) }}</span>@endif
                @if($pct !== null) · <span class="app-num {{ $pct > 100 ? 'text-danger fw-semibold' : '' }}">{{ $pct }}%</span>@endif
            </span>
        </div>
        <div class="progress" style="block-size:10px" role="progressbar" aria-valuenow="{{ $pct ?? 0 }}" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar {{ $tone }}" style="inline-size: {{ $pct === null ? 0 : min($pct, 100) }}%"></div>
        </div>
        @if($capacity === null)<div class="form-text">{{ __('This container type has no capacity set on the Lists & options screen.') }}</div>@endif
        @if($pct !== null && $pct > 100)<div class="text-danger small mt-1">{{ __('Over capacity.') }}</div>@endif
    </div>
@endif
