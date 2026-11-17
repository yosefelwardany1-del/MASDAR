@extends('layouts.app')

@section('title', $shipment->number)

@use('Illuminate\Support\HtmlString')

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $queries = app(\App\Services\Shipping\ShippingQueries::class);
    $comma = app()->getLocale() === 'ar' ? '، ' : ', ';
    $pageTitle = new HtmlString(e(__('Shipment')).' <span class="app-ltr">'.e($shipment->number).'</span>');
    $company = $shipment->shippingCompany;
    $companyUrl = $company ? $link('shipping-companies.show', $company) : null;
    $ltr = fn (?string $text) => new HtmlString('<span class="app-ltr">'.e($text ?: '—').'</span>');
    $detailItems = [
        __('Shipping company') => $company ? ($companyUrl ? new HtmlString('<a href="'.e($companyUrl).'">'.e($company->name).'</a>') : $company->name) : null,
        __('Shipping mode') => $shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : null,
        __('Booking no.') => $ltr($shipment->booking_no),
        __('B/L no.') => $ltr($shipment->bl_number),
        __('Vessel').' / '.__('Voyage') => $ltr(($shipment->vessel ?: '—').' / '.($shipment->voyage ?: '—')),
        __('Created by') => new HtmlString('<bdi>'.e($shipment->creator?->name ?? '—').'</bdi> · '.e($shipment->created_at?->diffForHumans())),
    ];
    $addFailed = $errors->hasAny(['container_no', 'container_type', 'seal_no', 'notes']);
@endphp

<x-page-header :title="$pageTitle" :back="route('shipments.index')" :back-label="__('All shipments')"
               :subtitle="collect([$company?->name, $shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : null])->filter()->join(' · ') ?: null">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-box-seam" aria-hidden="true"></i>{{ trans_choice('{0} No containers|{1} :count container|[2,*] :count containers', $containers->count(), ['count' => $containers->count()]) }}</span>
        <span class="app-pill"><i class="bi bi-kanban" aria-hidden="true"></i>{{ trans_choice('{0} No orders booked|{1} :count order booked|[2,*] :count orders booked', $bookings->count(), ['count' => $bookings->count()]) }}</span>
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('containers.index') }}" class="btn btn-light"><i class="bi bi-boxes" aria-hidden="true"></i>{{ __('Container board') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.edit', $shipment) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <x-section :title="__('Route')" icon="signpost-split" class="h-100">
            @include('containers._route-strip', ['shipment' => $shipment])
            @if($shipment->notes)
                <div class="mt-3 pt-3 border-top">
                    <div class="app-fu-summary__label mb-1">{{ __('Notes') }}</div>
                    <div>{!! nl2br(e($shipment->notes)) !!}</div>
                </div>
            @endif
        </x-section>
    </div>
    <div class="col-lg-5">
        <x-section :title="__('Details')" icon="info-circle" class="h-100">
            <x-detail-list :cols="2" :items="$detailItems" />
        </x-section>
    </div>
</div>

<x-section :title="__('Containers')" icon="box-seam" flush class="mb-3">
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Container') }}</th><th>{{ __('Status') }}</th><th>{{ __('Clients') }}</th>
                <th>{{ __('Fill') }}</th><th class="text-center"><i class="bi bi-paperclip" title="{{ __('Attachments') }}" aria-hidden="true"></i><span class="visually-hidden">{{ __('Attachments') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($containers as $c)
                @php $flag = $queries->etaFlag($c->eta, $c->status_sort, $arrivedSort); @endphp
                <tr @class(['app-fu-row--late' => isset($flag['late'])])>
                    <td style="min-inline-size:10rem">
                        <a href="{{ route('containers.show', $c) }}" class="app-fu-order__no"><span class="app-ltr">{{ $c->container_no ?: '#'.$c->id }}</span></a>
                        <div class="app-fu-sub">{{ $c->container_type ? ($types[$c->container_type] ?? $c->container_type) : '—' }}</div>
                        @if($c->seal_no)<div class="app-fu-sub">{{ __('Seal') }} <span class="app-ltr">{{ $c->seal_no }}</span></div>@endif
                    </td>
                    <td style="min-inline-size:12rem">
                        <div class="app-fu-phases app-fu-mini">@include('containers._phases', ['statusSort' => $c->status_sort, 'sorts' => $statusSorts, 'flag' => $flag, 'mode' => $c->mode, 'etd' => $c->etd, 'eta' => $c->eta])</div>
                        <div class="app-fu-statusline d-flex flex-wrap gap-1">
                            @include('containers._status', ['status' => $c->status, 'statusSort' => $c->status_sort])
                            @include('containers._eta', ['flag' => $flag])
                        </div>
                    </td>
                    <td class="app-fu-wrap">{{ ($containerClients[$c->id] ?? collect())->pluck('name')->join($comma) ?: '—' }}</td>
                    <td style="min-inline-size:11rem">
                        @include('containers._fill', ['percent' => $c->fill_percent, 'loaded' => $c->loaded_cbm, 'capacity' => $c->capacity_cbm, 'unit' => 'CBM', 'compact' => true,
                            'kg' => ['loaded' => $c->loaded_kg, 'capacity' => $c->capacity_kg]])
                    </td>
                    <td class="app-num text-center">{{ $c->attachments_count }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="box-seam" :title="__('No containers on this shipment yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @can('containers.edit')
        <div class="app-fu-foot">
            <details class="app-fu-disclosure" @if($addFailed || $containers->isEmpty()) open @endif>
                <summary class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add a container') }}</summary>
                <form method="POST" action="{{ route('containers.store', $shipment) }}" class="mt-3">
                    @csrf
                    @include('containers._form', ['container' => null, 'idPrefix' => 'new-ct'])
                    <button class="btn btn-primary mt-3"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add container') }}</button>
                </form>
            </details>
        </div>
    @endcan
</x-section>

<x-section :title="__('Orders booked on this shipment')" icon="clipboard-check" flush>
    @error('supplier_order_id')<div class="alert alert-danger m-3 mb-0" role="alert">{{ $message }}</div>@enderror
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Supplier order') }}</th>
                @if($seesSuppliers)<th>{{ __('Supplier') }}</th>@endif
                <th>{{ __('Clients') }}</th><th>{{ __('Booked') }}</th><th><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($bookings as $booking)
                @php $order = $booking->supplierOrder; $orderUrl = $link('supplier-orders.show', $order); @endphp
                <tr>
                    <td>@if($orderUrl)<a href="{{ $orderUrl }}" class="app-fu-order__no"><span class="app-ltr">{{ $order->number }}</span></a>@else<span class="app-ltr fw-semibold">{{ $order->number }}</span>@endif</td>
                    @if($seesSuppliers)<td>{{ $order->supplier?->name ?? '—' }}</td>@endif
                    <td class="app-fu-wrap">{{ ($orderClients[$order->id] ?? collect())->pluck('name')->join($comma) ?: '—' }}</td>
                    <td class="app-fu-sub"><bdi>{{ $booking->user?->name }}</bdi> · {{ $booking->created_at?->diffForHumans() }}</td>
                    <td class="text-end">
                        @can('containers.edit')
                            <form method="POST" action="{{ route('shipments.orders.destroy', [$shipment, $order]) }}" onsubmit="return confirm(@js(__('Take this order off the shipment?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger"><i class="bi bi-x-lg" aria-hidden="true"></i>{{ __('Unbook') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $seesSuppliers ? 5 : 4 }}" class="p-0"><x-empty icon="clipboard" :title="__('No order is booked on this shipment yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('shipments.orders.store', $shipment) }}" class="app-fu-foot">
            @csrf
            <label class="app-fu-foot__title" for="book-order"><i class="bi bi-plus-circle" aria-hidden="true"></i>{{ __('Book an order') }}</label>
            <div class="app-fu-inlineform" style="max-inline-size:40rem">
                <select id="book-order" name="supplier_order_id" class="form-select" required @disabled($bookable->isEmpty())>
                    <option value="">{{ $bookable->isEmpty() ? __('No open supplier order to book') : __('Choose…') }}</option>
                    @foreach($bookable as $order)
                        @php $names = ($orderClients[$order->id] ?? collect())->pluck('name')->join($comma); @endphp
                        <option value="{{ $order->id }}">{{ $order->number }}{{ $names ? ' — '.$names : '' }}{{ $seesSuppliers && $order->supplier ? ' ('.$order->supplier->name.')' : '' }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" @disabled($bookable->isEmpty())><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Book the order') }}</button>
            </div>
        </form>
    @endcan
</x-section>
@endsection
