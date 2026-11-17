{{-- How full a container is. Needs $percent (numeric string or null), $loaded, $capacity (numeric strings or null), $unit;
     optional $compact (a bar for a table row) and $kg (['loaded', 'capacity'], a second line under a compact bar).
     The full form is a gauge: the figure in its middle, "loaded / capacity" under it, and a word once it is nearly full or over. --}}
@php
    $num = fn ($v) => \App\Services\Shipping\Num::show($v);
    $pct = $percent === null ? null : (int) $percent;
    $zone = $pct === null ? 'none' : ($pct > 100 ? 'over' : ($pct >= 90 ? 'near' : 'ok'));
    $unitLabel = __($unit);
@endphp
@if(! empty($compact))
    <div class="app-fu-fill">
        @if($pct === null)
            <div class="app-fu-sub"><span class="app-fu-strong app-num">{{ $num($loaded) }}</span> {{ $unitLabel }} · {{ __('No capacity set') }}</div>
        @else
            <x-progress :value="$pct" :max="100" thin :label="$unitLabel"
                        :caption="$num($loaded).' / '.$num($capacity).' · '.$pct.'%'"
                        :tone="$zone === 'near' ? 'due' : 'primary'" />
            @if($zone === 'over')
                <span class="app-fu-fill__flag is-over app-fu-sub"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ __('Over capacity') }}</span>
            @elseif($zone === 'near')
                <span class="app-fu-fill__flag is-near app-fu-sub"><i class="bi bi-box-seam" aria-hidden="true"></i>{{ __('Nearly full') }}</span>
            @endif
        @endif
        @isset($kg)
            @php $kgOver = $kg['capacity'] !== null && bccomp((string) ($kg['loaded'] ?? '0'), (string) $kg['capacity'], 4) > 0; @endphp
            <div class="app-fu-fill__kg">{{ __('KG') }} <span class="app-num {{ $kgOver ? 'text-danger fw-semibold' : '' }}">{{ $num($kg['loaded']) }}</span>@if($kg['capacity'] !== null) / <span class="app-num">{{ $num($kg['capacity']) }}</span>@endif
                @if($kgOver)<span class="app-fu-fill__flag is-over"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ __('Over capacity') }}</span>@endif</div>
        @endisset
    </div>
@else
    @php
        $arc = $pct === null ? 0 : min($pct, 100);
        $label = $pct === null
            ? $unitLabel.': '.$num($loaded).' — '.__('No capacity set')
            : $unitLabel.': '.$num($loaded).' / '.$num($capacity).' — '.$pct.'%';
    @endphp
    <div class="app-fu-gauge is-{{ $zone }} @if($pct === null) is-empty @endif">
        <div class="app-fu-gauge__dial">
            <svg class="app-fu-gauge__svg" viewBox="0 0 120 66" role="img" aria-label="{{ $label }}">
                <path class="app-fu-gauge__track" d="M10 60 A50 50 0 0 1 110 60" pathLength="100" />
                @if($arc > 0)
                    <path class="app-fu-gauge__bar" d="M10 60 A50 50 0 0 1 110 60" pathLength="100" stroke-dasharray="{{ $arc }} 100" />
                @endif
            </svg>
            <span class="app-fu-gauge__value" aria-hidden="true">{{ $pct === null ? '—' : $pct.'%' }}</span>
        </div>
        <div class="app-fu-gauge__unit">{{ $unitLabel }}</div>
        <div class="app-fu-gauge__figures">
            <strong class="app-num">{{ $num($loaded) }}</strong>@if($capacity !== null) / <span class="app-num">{{ $num($capacity) }}</span>@endif
        </div>
        @if($zone === 'over')
            <x-status tone="late">{{ __('Over capacity') }}</x-status>
        @elseif($zone === 'near')
            <x-status tone="due" icon="box-seam">{{ __('Nearly full') }}</x-status>
        @elseif($pct === null)
            <span class="app-fu-gauge__note text-muted">{{ __('No capacity set') }}</span>
        @endif
    </div>
@endif
