@extends('layouts.portal')

@section('title', $container->container_no ?: __('Container'))

@php $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

@section('content')
<x-page-header :title="__('Container :no', ['no' => $container->container_no ?: '—'])" :back="route('portal.home')" :back-label="__('My orders')"
               :subtitle="trim(($container->shipment?->vessel ?? '').' '.($container->shipment?->voyage ?? ''))">
    @if($tracking)
        <x-slot:actions><a href="{{ $tracking }}" target="_blank" rel="noopener noreferrer" class="btn btn-light"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ __('Track on the shipping line') }}</a></x-slot:actions>
    @endif
</x-page-header>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('ETD')" :value="$container->shipment?->etd?->format('Y-m-d') ?? '—'" icon="box-arrow-up-right" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('ETA')" :value="$container->shipment?->eta?->format('Y-m-d') ?? '—'" icon="geo-alt" tone="gold" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('B/L')" :value="$container->shipment?->bl_number ?? '—'" icon="file-earmark-text" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Your cartons')" :value="$plain($lines->sum('cartons'))" icon="box-seam" /></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <x-section :title="__('Your goods inside')" icon="box-seam" flush>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Proforma invoice') }}</th><th scope="col" class="text-end">{{ __('Cartons') }}</th><th scope="col" class="text-end">{{ __('CBM') }}</th></tr></thead>
                    <tbody>
                    @foreach($lines as $l)
                        <tr><td>{{ \Illuminate\Support\Str::limit((string) $l->description, 70) }}</td><td><span class="app-ltr">{{ $l->pi_number }}</span></td>
                            <td class="text-end"><span class="app-num">{{ $plain($l->cartons) }}</span></td><td class="text-end"><span class="app-num">{{ $plain($l->cbm) }}</span></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>
    </div>
    <div class="col-lg-5">
        <x-section :title="__('Where it is')" icon="signpost-split" flush>
            <ol class="list-unstyled mb-0">
                @forelse($container->events->reverse() as $e)
                    <li class="px-3 py-2 border-bottom"><strong>{{ $statuses[$e->status] ?? $e->status }}</strong>
                        <div class="small text-muted"><span class="app-num">{{ $e->happened_at->timezone(config('app.timezone'))->format('Y-m-d') }}</span>@if($e->place) · {{ $e->place }}@endif</div></li>
                @empty
                    <li class="px-3 py-2 text-muted">{{ __('Booked; not loaded yet.') }}</li>
                @endforelse
            </ol>
        </x-section>
    </div>
</div>
@endsection
