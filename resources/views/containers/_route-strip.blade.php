{{-- The voyage as a strip: port of loading and ETD at the start, port of discharge and ETA at the end, the mode and vessel between.
     Needs $ports (code => name), $modes (code => name) and $shipment. --}}
@php
    $icon = match (true) {
        $shipment->mode === 'air' => 'airplane',
        $shipment->mode === 'rail' => 'train-front',
        $shipment->mode === 'express' => 'truck',
        default => 'water',
    };
    $middle = collect([$shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : null, $shipment->vessel, $shipment->voyage])->filter()->join(' · ');
@endphp
<div class="app-fu-route">
    <div class="app-fu-route__end">
        <div class="app-fu-route__label">{{ __('Port of loading') }}</div>
        <div class="app-fu-route__port">{{ $shipment->port_of_loading ? ($ports[$shipment->port_of_loading] ?? $shipment->port_of_loading) : '—' }}</div>
        <div class="app-fu-route__date">{{ __('ETD') }} <span class="app-num">{{ $shipment->etd?->toDateString() ?? '—' }}</span></div>
    </div>
    <div class="app-fu-route__line" aria-hidden="{{ $middle === '' ? 'true' : 'false' }}">
        <span><i class="bi bi-{{ $icon }}" aria-hidden="true"></i>@if($middle !== '')<bdi>{{ $middle }}</bdi>@endif</span>
    </div>
    <div class="app-fu-route__end">
        <div class="app-fu-route__label">{{ __('Port of discharge') }}</div>
        <div class="app-fu-route__port">{{ $shipment->port_of_discharge ? ($ports[$shipment->port_of_discharge] ?? $shipment->port_of_discharge) : '—' }}</div>
        <div class="app-fu-route__date">{{ __('ETA') }} <span class="app-num">{{ $shipment->eta?->toDateString() ?? '—' }}</span></div>
    </div>
</div>
