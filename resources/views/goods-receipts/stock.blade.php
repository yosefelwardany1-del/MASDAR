@extends('layouts.app')

@section('title', __('In the warehouse'))

@php $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0'; @endphp

@section('content')
<x-page-header :title="__('In the warehouse')" :back="route('goods-receipts.index')" :back-label="__('Goods receipt & inspection')"
               :subtitle="__('Goods received from the factories and not loaded yet: what waits in Yiwu, for whom, and for how long.')" />

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Cartons waiting')" :value="$plain($cartons)" icon="box-seam" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('CBM waiting')" :value="$plain(bcadd($cbm, '0', 2))" icon="rulers" tone="gold" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Lines')" :value="$lines->count()" icon="list-ul" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Longest wait')" :value="$lines->max('days') !== null ? trans_choice('{0} today|{1} one day|[2,*] :count days', $lines->max('days'), ['count' => $lines->max('days')]) : '—'" icon="hourglass-split" :tone="($lines->max('days') ?? 0) > 14 ? 'late' : null" /></div>
</div>

<x-section :title="__('Waiting to be loaded')" icon="house-door" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Item') }}</th><th scope="col">{{ __('Order') }}</th><th scope="col" class="text-end">{{ __('Received') }}</th><th scope="col" class="text-end">{{ __('Loaded') }}</th><th scope="col" class="text-end">{{ __('Waiting') }}</th><th scope="col" class="text-end">{{ __('CBM') }}</th><th scope="col">{{ __('Where') }}</th><th scope="col">{{ __('Since') }}</th></tr></thead>
            <tbody>
            @forelse($lines as $l)
                <tr>
                    <td><bdi>{{ $l->client }}</bdi><div class="small text-muted app-ltr">{{ $l->pi_number }}</div></td>
                    <td><span class="d-inline-block text-truncate" style="max-inline-size: 18rem">{{ $l->description }}</span></td>
                    <td>@if($seesSuppliers)<a href="{{ route('supplier-orders.show', $l->order_id) }}" class="app-ltr">{{ $l->order_number }}</a><div class="small text-muted"><bdi>{{ $l->supplier }}</bdi></div>@else<span class="app-ltr">{{ $l->order_number }}</span>@endif</td>
                    <td class="text-end"><span class="app-num">{{ $plain($l->received) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $plain($l->loaded) }}</span></td>
                    <td class="text-end"><span class="app-num fw-semibold">{{ $plain($l->in_stock) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $plain(bcadd($l->cbm, '0', 3)) }}</span></td>
                    <td>{{ $l->location ?? '—' }}</td>
                    <td class="text-nowrap"><span class="app-num">{{ \Illuminate\Support\Carbon::parse($l->first_on)->format('Y-m-d') }}</span><div class="small {{ $l->days > 14 ? 'text-danger' : 'text-muted' }}">{{ trans_choice('{0} today|{1} one day|[2,*] :count days', $l->days, ['count' => $l->days]) }}</div></td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-0"><x-empty icon="house-door" :title="__('The warehouse is empty.')" :text="__('Everything received has been loaded.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
