@extends('layouts.app')

@section('title', $container->container_no ?: __('Container').' #'.$container->id)

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $num = fn ($v) => \App\Services\Shipping\Num::show($v);
    $arrivedSort = app(\App\Services\Shipping\ShippingQueries::class)->arrivedSort();
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1">{{ __('Container') }} <span class="app-ltr">{{ $container->container_no ?: '#'.$container->id }}</span></h1>
        <div class="d-flex flex-wrap align-items-center gap-2">
            @include('containers._status', ['status' => $row->status, 'statusSort' => $row->status_sort])
            @include('containers._eta', ['flag' => $eta])
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('containers.index') }}" class="btn btn-light">{{ __('Container board') }}</a>
        <a href="{{ route('containers.packing-list', $container) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer me-1"></i>{{ __('Packing list') }}</a>
        @can('containers.edit')
            <a href="{{ route('containers.edit', $container) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
        @endcan
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="app-card p-4 h-100">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Container type') }}</dt><dd class="col-sm-8">{{ $container->container_type ? ($types[$container->container_type] ?? $container->container_type) : '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Seal no.') }}</dt><dd class="col-sm-8"><span class="app-ltr">{{ $container->seal_no ?: '—' }}</span></dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Shipment') }}</dt><dd class="col-sm-8"><a href="{{ route('shipments.show', $shipment) }}" class="app-ltr">{{ $shipment->number }}</a></dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Shipping company') }}</dt>
                <dd class="col-sm-8">
                    @if($shipment->shippingCompany)
                        @php $companyUrl = $link('shipping-companies.show', $shipment->shippingCompany); @endphp
                        @if($companyUrl)<a href="{{ $companyUrl }}">{{ $shipment->shippingCompany->name }}</a>@else{{ $shipment->shippingCompany->name }}@endif
                    @else — @endif
                </dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Shipping mode') }}</dt><dd class="col-sm-8">{{ $shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Route') }}</dt><dd class="col-sm-8">@include('containers._route', ['pol' => $shipment->port_of_loading, 'pod' => $shipment->port_of_discharge])</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('ETD') }} / {{ __('ETA') }}</dt>
                <dd class="col-sm-8"><span class="app-num">{{ $shipment->etd?->toDateString() ?? '—' }}</span> / <span class="app-num">{{ $shipment->eta?->toDateString() ?? '—' }}</span></dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Vessel') }} / {{ __('Voyage') }}</dt><dd class="col-sm-8"><span class="app-ltr">{{ $shipment->vessel ?: '—' }} / {{ $shipment->voyage ?: '—' }}</span></dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Booking no.') }} / {{ __('B/L no.') }}</dt><dd class="col-sm-8"><span class="app-ltr">{{ $shipment->booking_no ?: '—' }} / {{ $shipment->bl_number ?: '—' }}</span></dd>
                @if($container->notes)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Notes') }}</dt><dd class="col-sm-8">{{ $container->notes }}</dd>
                @endif
            </dl>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="app-card p-4 h-100">
            <div class="fw-semibold mb-3"><i class="bi bi-speedometer2 me-1"></i>{{ __('Fill') }}</div>
            @include('containers._fill', ['percent' => $row->fill_percent, 'loaded' => $row->loaded_cbm, 'capacity' => $row->capacity_cbm, 'unit' => 'CBM'])
            @include('containers._fill', ['percent' => $row->fill_kg_percent, 'loaded' => $row->loaded_kg, 'capacity' => $row->capacity_kg, 'unit' => 'KG'])
        </div>
    </div>
</div>

<div class="app-card mb-3">
    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-signpost-split me-1"></i>{{ __('Status timeline') }}</div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>#</th><th>{{ __('Status') }}</th><th>{{ __('Date and time') }}</th><th>{{ __('Place') }}</th><th>{{ __('Note') }}</th><th>{{ __('By') }}</th></tr></thead>
            <tbody>
            @forelse($container->events as $event)
                <tr>
                    <td class="app-num text-muted">{{ $event->id }}</td>
                    <td>
                        <span class="app-pill">{{ $statuses[$event->status] ?? $event->status }}</span>
                        @if($event->isCorrection())
                            <span class="app-pill app-pill--due" title="{{ __('Correction') }}"><i class="bi bi-arrow-counterclockwise"></i>{{ __('Corrects #:id', ['id' => $event->corrects_event_id]) }}</span>
                        @endif
                    </td>
                    <td class="app-num">{{ $event->happened_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $event->place ?: '—' }}</td>
                    <td>{{ $event->note ?: '—' }}</td>
                    <td class="small text-muted">{{ $event->user?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="app-empty py-4">{{ __('No status recorded yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('containers.events.store', $container) }}" class="p-3 border-top">
            @csrf
            <div class="fw-semibold mb-2">{{ __('Record status') }}</div>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">{{ __('Status') }} <span class="text-danger">*</span></label>
                    <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                        @foreach($statusOptions as $code => $name)
                            <option value="{{ $code }}" @selected(old('status') === (string) $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Date and time') }} <span class="text-danger">*</span></label>
                    <input type="datetime-local" name="happened_at" class="form-control @error('happened_at') is-invalid @enderror" value="{{ old('happened_at', now()->format('Y-m-d\TH:i')) }}" required>
                    @error('happened_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('Place') }}</label>
                    <input type="text" name="place" class="form-control @error('place') is-invalid @enderror" value="{{ old('place') }}" maxlength="160">
                    @error('place')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label">{{ __('This corrects event') }}</label>
                    <select name="corrects_event_id" class="form-select @error('corrects_event_id') is-invalid @enderror">
                        <option value="">{{ __('No — a new status') }}</option>
                        @foreach($container->events->reverse() as $event)
                            <option value="{{ $event->id }}" @selected((string) old('corrects_event_id') === (string) $event->id)>#{{ $event->id }} — {{ $statuses[$event->status] ?? $event->status }} ({{ $event->happened_at?->format('Y-m-d') }})</option>
                        @endforeach
                    </select>
                    @error('corrects_event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <label class="form-label">{{ __('Note') }}</label>
                    <input type="text" name="note" class="form-control @error('note') is-invalid @enderror" value="{{ old('note') }}" maxlength="2000">
                    @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">{{ __('A status only moves forward. To go back, choose the event this one corrects.') }}</div>
                </div>
            </div>
            <button class="btn btn-primary mt-3">{{ __('Record status') }}</button>
        </form>
    @endcan
</div>

<div class="app-card mb-3">
    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-boxes me-1"></i>{{ __('Loaded in this container') }}</div>
    @error('supplier_order_line_id')<div class="alert alert-danger m-3 mb-0">{{ $message }}</div>@enderror
    @error('cartons')<div class="alert alert-danger m-3 mb-0">{{ $message }}</div>@enderror
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th></th><th>{{ __('Client') }}</th><th>{{ __('PI') }}</th><th>{{ __('Supplier order') }}</th><th>{{ __('Item') }}</th>
                <th>{{ __('Cartons') }}</th><th>{{ __('Quantity') }}</th><th>{{ __('CBM') }}</th><th>{{ __('KG') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($contents as $line)
                @php
                    $photoUrl = $line->product_id && $line->product_photo ? $link('products.photo', $line->product_id) : null;
                    $piUrl = $link('proforma-invoices.show', $line->proforma_invoice_id);
                    $orderUrl = $link('supplier-orders.show', $line->supplier_order_id);
                    $clientUrl = $link('clients.show', $line->client_id);
                @endphp
                <tr>
                    <td>@if($photoUrl)<img src="{{ $photoUrl }}" alt="" class="app-thumb" loading="lazy" onerror="this.style.display='none'">@endif</td>
                    <td>@if($clientUrl)<a href="{{ $clientUrl }}">{{ $line->client_name }}</a>@else{{ $line->client_name }}@endif</td>
                    <td>@if($piUrl)<a href="{{ $piUrl }}" class="app-ltr">{{ $line->pi_number }}</a>@else<span class="app-ltr">{{ $line->pi_number }}</span>@endif</td>
                    <td>@if($orderUrl)<a href="{{ $orderUrl }}" class="app-ltr">{{ $line->supplier_order_number }}</a>@else<span class="app-ltr">{{ $line->supplier_order_number }}</span>@endif
                        @if($seesSuppliers && $line->supplier_name)<div class="small text-muted">{{ $line->supplier_name }}</div>@endif</td>
                    <td style="white-space:normal; min-inline-size:220px">{{ $line->product_name }}@if($line->description)<div class="small text-muted">{{ $line->description }}</div>@endif</td>
                    <td class="app-num">{{ $num($line->cartons) }}</td>
                    <td class="app-num">{{ $num($line->qty) }}</td>
                    <td class="app-num">{{ $num($line->cbm) }}</td>
                    <td class="app-num">{{ $num($line->kg) }}</td>
                    <td class="text-end">
                        @can('containers.edit')
                            <details>
                                <summary class="btn btn-sm btn-light text-danger">{{ __('Unload') }}</summary>
                                <form method="POST" action="{{ route('containers.unloads.store', $container) }}" class="d-flex gap-1 mt-1">
                                    @csrf
                                    <input type="hidden" name="supplier_order_line_id" value="{{ $line->supplier_order_line_id }}">
                                    <input type="number" name="cartons" class="form-control form-control-sm" style="inline-size:90px" min="0" step="any" value="{{ \App\Services\Shipping\Num::plain($line->cartons) }}" required aria-label="{{ __('Cartons') }}">
                                    <input type="date" name="unloaded_on" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required aria-label="{{ __('Date') }}">
                                    <button class="btn btn-sm btn-danger">{{ __('Unload') }}</button>
                                </form>
                            </details>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="app-empty py-4">{{ __('Nothing loaded yet.') }}</td></tr>
            @endforelse
            </tbody>
            @if($contents->isNotEmpty())
                <tfoot><tr class="fw-semibold">
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="app-num">{{ $num($totals->cartons) }}</td>
                    <td class="app-num">{{ $num($totals->qty) }}</td>
                    <td class="app-num">{{ $num($totals->cbm) }}</td>
                    <td class="app-num">{{ $num($totals->kg) }}</td>
                    <td></td>
                </tr></tfoot>
            @endif
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('containers.loads.store', $container) }}" class="p-3 border-top">
            @csrf
            <div class="fw-semibold mb-2">{{ __('Load cartons') }}</div>
            @if($loadable->isEmpty())
                <p class="text-muted small mb-0">{{ __('Nothing to load: book an order on this shipment and receive its goods first.') }}</p>
            @else
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">{{ __('Order line') }} <span class="text-danger">*</span></label>
                        <select name="supplier_order_line_id" class="form-select" required>
                            @foreach($loadable as $l)
                                <option value="{{ $l->supplier_order_line_id }}" @selected((string) old('supplier_order_line_id') === (string) $l->supplier_order_line_id)>
                                    {{ $l->supplier_order_number }} · {{ $l->client_name }} · {{ $l->pi_number }} · {{ \Illuminate\Support\Str::limit($l->product_name ?: $l->description, 40) }} — {{ __(':count cartons available', ['count' => $num($l->available)]) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('Cartons') }} <span class="text-danger">*</span></label>
                        <input type="number" name="cartons" class="form-control" min="0" step="any" value="{{ old('cartons') }}" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                        <input type="date" name="loaded_on" class="form-control @error('loaded_on') is-invalid @enderror" value="{{ old('loaded_on', now()->toDateString()) }}" required>
                        @error('loaded_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-down me-1"></i>{{ __('Load') }}</button>
                    </div>
                </div>
            @endif
        </form>
    @endcan
</div>

@include('partials.attachments', ['attachable' => $container, 'type' => 'containers'])
<p class="text-muted small mt-2">{{ __('Attach the B/L, the packing list, the commercial invoice, the certificate of origin and loading photos here.') }}</p>
@endsection
