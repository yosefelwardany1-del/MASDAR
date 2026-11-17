@extends('layouts.app')

@section('title', $shipment->number)

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $queries = app(\App\Services\Shipping\ShippingQueries::class);
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Shipment') }} <span class="app-ltr">{{ $shipment->number }}</span></h1>
    <div class="d-flex gap-2">
        <a href="{{ route('shipments.index') }}" class="btn btn-light">{{ __('All shipments') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.edit', $shipment) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
        @endcan
    </div>
</div>

<div class="app-card p-4 mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Shipping company') }}</dt>
        <dd class="col-sm-9">
            @if($shipment->shippingCompany)
                @php $companyUrl = $link('shipping-companies.show', $shipment->shippingCompany); @endphp
                @if($companyUrl)<a href="{{ $companyUrl }}">{{ $shipment->shippingCompany->name }}</a>@else{{ $shipment->shippingCompany->name }}@endif
            @else — @endif
        </dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Shipping mode') }}</dt><dd class="col-sm-9">{{ $shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : '—' }}</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Booking no.') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $shipment->booking_no ?: '—' }}</span></dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Route') }}</dt><dd class="col-sm-9">@include('containers._route', ['pol' => $shipment->port_of_loading, 'pod' => $shipment->port_of_discharge])</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('ETD') }} / {{ __('ETA') }}</dt>
        <dd class="col-sm-9"><span class="app-num">{{ $shipment->etd?->toDateString() ?? '—' }}</span> / <span class="app-num">{{ $shipment->eta?->toDateString() ?? '—' }}</span></dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Vessel') }} / {{ __('Voyage') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $shipment->vessel ?: '—' }} / {{ $shipment->voyage ?: '—' }}</span></dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('B/L no.') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $shipment->bl_number ?: '—' }}</span></dd>
        @if($shipment->notes)
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Notes') }}</dt><dd class="col-sm-9">{!! nl2br(e($shipment->notes)) !!}</dd>
        @endif
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Created by') }}</dt><dd class="col-sm-9">{{ $shipment->creator?->name ?? '—' }} · {{ $shipment->created_at?->diffForHumans() }}</dd>
    </dl>
</div>

<div class="app-card mb-3">
    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-box-seam me-1"></i>{{ __('Containers') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $containers->count() }}</span></div>
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Container') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Clients') }}</th>
                <th>{{ __('CBM') }}</th><th>{{ __('KG') }}</th><th>{{ __('Fill') }}</th><th><i class="bi bi-paperclip" title="{{ __('Attachments') }}"></i></th>
            </tr></thead>
            <tbody>
            @forelse($containers as $c)
                <tr>
                    <td><a href="{{ route('containers.show', $c) }}" class="app-ltr fw-semibold">{{ $c->container_no ?: '#'.$c->id }}</a>
                        @if($c->seal_no)<div class="small text-muted">{{ __('Seal') }} <span class="app-ltr">{{ $c->seal_no }}</span></div>@endif</td>
                    <td>{{ $c->container_type ? ($types[$c->container_type] ?? $c->container_type) : '—' }}</td>
                    <td>@include('containers._status', ['status' => $c->status, 'statusSort' => $c->status_sort])
                        @include('containers._eta', ['flag' => $queries->etaFlag($c->eta, $c->status_sort, $arrivedSort)])</td>
                    <td>{{ ($containerClients[$c->id] ?? collect())->pluck('name')->join('، ') ?: '—' }}</td>
                    <td class="app-num">{{ \App\Services\Shipping\Num::show($c->loaded_cbm) }}</td>
                    <td class="app-num">{{ \App\Services\Shipping\Num::show($c->loaded_kg) }}</td>
                    <td>@include('containers._fill', ['percent' => $c->fill_percent, 'loaded' => $c->loaded_cbm, 'capacity' => $c->capacity_cbm, 'unit' => 'CBM', 'compact' => true])</td>
                    <td class="app-num">{{ $c->attachments_count }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="app-empty py-4">{{ __('No containers on this shipment yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('containers.store', $shipment) }}" class="p-3 border-top">
            @csrf
            <div class="fw-semibold mb-2">{{ __('Add a container') }}</div>
            @include('containers._form', ['container' => null])
            <button class="btn btn-primary mt-3"><i class="bi bi-plus-lg me-1"></i>{{ __('Add container') }}</button>
        </form>
    @endcan
</div>

<div class="app-card mb-3">
    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-clipboard-check me-1"></i>{{ __('Orders booked on this shipment') }} <span class="badge bg-secondary-subtle text-secondary-emphasis">{{ $bookings->count() }}</span></div>
    @error('supplier_order_id')<div class="alert alert-danger m-3 mb-0">{{ $message }}</div>@enderror
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Supplier order') }}</th>
                @if($seesSuppliers)<th>{{ __('Supplier') }}</th>@endif
                <th>{{ __('Clients') }}</th><th>{{ __('Booked') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($bookings as $booking)
                @php $order = $booking->supplierOrder; $orderUrl = $link('supplier-orders.show', $order); @endphp
                <tr>
                    <td>@if($orderUrl)<a href="{{ $orderUrl }}" class="app-ltr">{{ $order->number }}</a>@else<span class="app-ltr">{{ $order->number }}</span>@endif</td>
                    @if($seesSuppliers)<td>{{ $order->supplier?->name ?? '—' }}</td>@endif
                    <td>{{ ($orderClients[$order->id] ?? collect())->pluck('name')->join('، ') ?: '—' }}</td>
                    <td class="small text-muted">{{ $booking->user?->name }} · {{ $booking->created_at?->diffForHumans() }}</td>
                    <td class="text-end">
                        @can('containers.edit')
                            <form method="POST" action="{{ route('shipments.orders.destroy', [$shipment, $order]) }}" onsubmit="return confirm(@js(__('Take this order off the shipment?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger">{{ __('Unbook') }}</button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="{{ $seesSuppliers ? 5 : 4 }}" class="app-empty py-4">{{ __('No order is booked on this shipment yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('shipments.orders.store', $shipment) }}" class="p-3 border-top d-flex flex-wrap gap-2 align-items-end">
            @csrf
            <div class="flex-grow-1" style="max-inline-size:520px">
                <label class="form-label">{{ __('Book an order') }}</label>
                <select name="supplier_order_id" class="form-select" required @disabled($bookable->isEmpty())>
                    <option value="">{{ $bookable->isEmpty() ? __('No open supplier order to book') : __('Choose…') }}</option>
                    @foreach($bookable as $order)
                        @php $names = ($orderClients[$order->id] ?? collect())->pluck('name')->join('، '); @endphp
                        <option value="{{ $order->id }}">{{ $order->number }}{{ $names ? ' — '.$names : '' }}{{ $seesSuppliers && $order->supplier ? ' ('.$order->supplier->name.')' : '' }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-primary" @disabled($bookable->isEmpty())><i class="bi bi-plus-lg me-1"></i>{{ __('Book') }}</button>
        </form>
    @endcan
</div>
@endsection
