@extends('layouts.app')

@section('title', $container->container_no ?: __('Container').' #'.$container->id)

@use('Illuminate\Support\HtmlString')

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $num = fn ($v) => \App\Services\Shipping\Num::show($v);
    $arrivedSort = app(\App\Services\Shipping\ShippingQueries::class)->arrivedSort();
    $number = $container->container_no ?: '#'.$container->id;
    $pageTitle = new HtmlString(e(__('Container')).' <span class="app-ltr">'.e($number).'</span>');
    $typeName = $container->container_type ? ($types[$container->container_type] ?? $container->container_type) : null;
    $company = $shipment->shippingCompany;
    $companyUrl = $company ? $link('shipping-companies.show', $company) : null;

    // When the container first reached each phase of the stepper, from its events in order.
    $reachedOn = [];
    foreach ($container->events as $event) {
        $sort = $statusSorts[$event->status] ?? null;
        foreach (['booked', 'loaded_sealed', 'on_board', 'arrived'] as $code) {
            if ($sort !== null && isset($statusSorts[$code]) && $sort >= $statusSorts[$code] && ! isset($reachedOn[$code])) {
                $reachedOn[$code] = $event->happened_at?->format('Y-m-d');
            }
        }
    }
    $latest = $container->events->last();
    $events = $container->events->reverse()->values();
    $canEdit = auth()->user()->can('containers.edit');
    $ltr = fn (?string $text) => new HtmlString('<span class="app-ltr">'.e($text ?: '—').'</span>');
    $detailItems = [
        __('Container type') => $typeName,
        __('Seal no.') => $ltr($container->seal_no),
        __('Shipment') => new HtmlString('<a href="'.e(route('shipments.show', $shipment)).'" class="app-ltr">'.e($shipment->number).'</a>'),
        __('Shipping company') => $company ? ($companyUrl ? new HtmlString('<a href="'.e($companyUrl).'">'.e($company->name).'</a>') : $company->name) : null,
        __('Shipping mode') => $shipment->mode ? ($modes[$shipment->mode] ?? $shipment->mode) : null,
        __('Vessel').' / '.__('Voyage') => $ltr(($shipment->vessel ?: '—').' / '.($shipment->voyage ?: '—')),
        __('Booking no.') => $ltr($shipment->booking_no),
        __('B/L no.') => $ltr($shipment->bl_number),
    ];
@endphp

<x-page-header :title="$pageTitle" :back="route('containers.index')" :back-label="__('Container board')"
               :subtitle="collect([$typeName, $shipment->number, $company?->name])->filter()->join(' · ') ?: null">
    <x-slot:meta>
        @include('containers._status', ['status' => $row->status, 'statusSort' => $row->status_sort])
        @include('containers._eta', ['flag' => $eta])
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('containers.packing-list', $container) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Packing list') }}</a>
        @can('proforma_invoices.view')
            @php $invoiceClients = $contents->unique('client_id'); @endphp
            @if($invoiceClients->count() === 1)
                <a href="{{ route('containers.commercial-invoice', [$container, 'client' => $invoiceClients->first()->client_id]) }}" target="_blank" class="btn btn-light"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ __('Commercial invoice') }}</a>
            @elseif($invoiceClients->count() > 1)
                <div class="dropdown">
                    <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ __('Commercial invoice') }}</button>
                    <ul class="dropdown-menu">
                        @foreach($invoiceClients as $c)<li><a class="dropdown-item" target="_blank" href="{{ route('containers.commercial-invoice', [$container, 'client' => $c->client_id]) }}"><bdi>{{ $c->client_name }}</bdi></a></li>@endforeach
                    </ul>
                </div>
            @endif
        @endcan
        <div class="dropdown">
            <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send') }}</button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item" href="{{ route('documents.send.create', ['type' => 'packing-list', 'id' => $container->id]) }}">{{ __('Packing list') }}</a></li>
                @can('proforma_invoices.view')
                    @foreach($contents->unique('client_id') as $c)
                        <li><a class="dropdown-item" href="{{ route('documents.send.create', ['type' => 'commercial-invoice', 'id' => $container->id, 'client' => $c->client_id]) }}">{{ __('Commercial invoice') }} · <bdi>{{ $c->client_name }}</bdi></a></li>
                    @endforeach
                @endcan
            </ul>
        </div>
        @can('containers.edit')
            <a href="{{ route('containers.edit', $container) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

{{-- Where it is and when it lands. --}}
<section class="app-card app-section app-fu-journey mb-3" aria-labelledby="journey-title">
    <div class="app-card__head border-bottom">
        <i class="bi bi-signpost-split" aria-hidden="true"></i>
        <span class="app-section__title" id="journey-title">{{ __('Where the container is') }}</span>
    </div>
    <div class="app-section__body">
        @include('containers._phases', ['statusSort' => $row->status_sort, 'sorts' => $statusSorts, 'flag' => $eta, 'mode' => $shipment->mode,
            'etd' => $shipment->etd?->toDateString(), 'eta' => $shipment->eta?->toDateString(), 'reachedOn' => $reachedOn])
        @include('containers._route-strip', ['shipment' => $shipment])
        <div class="app-fu-journey__now mt-3">
            <span class="app-fu-summary__label">{{ __('Current status') }}</span>
            @if($latest)
                <span class="app-fu-strong">{{ $statuses[$latest->status] ?? $latest->status }}</span>
                <span><span class="app-num">{{ $latest->happened_at?->format('Y-m-d H:i') }}</span>@if($latest->place) · {{ $latest->place }}@endif</span>
            @else
                <span>{{ __('No status recorded yet.') }}</span>
            @endif
        </div>
    </div>
</section>

<div class="row g-3 mb-3 align-items-start">
    <div class="col-lg-7">
        {{-- Every status event, newest first; a correction names the event it corrects. --}}
        <x-section :title="__('Status timeline')" icon="clock-history" flush>
            @if($events->isEmpty())
                <x-empty icon="signpost" :title="__('No status recorded yet.')" :text="$canEdit ? __('Record the first status below once the container is booked.') : null" />
            @else
                <ol class="app-fu-events">
                    @foreach($events as $event)
                        <li @class(['app-fu-events__item', 'is-latest' => $loop->first, 'is-correction' => $event->isCorrection()])>
                            <span class="app-fu-events__dot" aria-hidden="true"></span>
                            <div>
                                <div class="app-fu-events__head">
                                    <span class="app-fu-events__status">{{ $statuses[$event->status] ?? $event->status }}</span>
                                    <span class="app-fu-events__when"><span class="app-num">{{ $event->happened_at?->format('Y-m-d H:i') }}</span>@if($event->place) · {{ $event->place }}@endif</span>
                                    @if($event->isCorrection())
                                        <span class="app-pill app-pill--due" title="{{ __('Correction') }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Corrects #:id', ['id' => $event->corrects_event_id]) }}</span>
                                    @endif
                                </div>
                                @if($event->note)<div class="app-fu-events__note">{{ $event->note }}</div>@endif
                                <div class="app-fu-events__meta"><span class="app-num">#{{ $event->id }}</span> · {{ $event->user?->name }}</div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
            @can('containers.edit')
                <form method="POST" action="{{ route('containers.events.store', $container) }}" class="app-fu-foot">
                    @csrf
                    <div class="app-fu-foot__title"><i class="bi bi-plus-circle" aria-hidden="true"></i>{{ __('Record status') }}</div>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <label class="form-label" for="ev-status">{{ __('Status') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                            <select id="ev-status" name="status" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach($statusOptions as $code => $name)
                                    <option value="{{ $code }}" @selected(old('status') === (string) $code)>{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="ev-at">{{ __('Date and time') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                            <input id="ev-at" type="datetime-local" name="happened_at" class="form-control @error('happened_at') is-invalid @enderror" value="{{ old('happened_at', now()->format('Y-m-d\TH:i')) }}" required>
                            @error('happened_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="ev-place">{{ __('Place') }}</label>
                            <input id="ev-place" type="text" name="place" class="form-control @error('place') is-invalid @enderror" value="{{ old('place') }}" maxlength="160">
                            @error('place')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="ev-corrects">{{ __('This corrects event') }}</label>
                            <select id="ev-corrects" name="corrects_event_id" class="form-select @error('corrects_event_id') is-invalid @enderror">
                                <option value="">{{ __('No — a new status') }}</option>
                                @foreach($container->events->reverse() as $event)
                                    <option value="{{ $event->id }}" @selected((string) old('corrects_event_id') === (string) $event->id)>#{{ $event->id }} — {{ $statuses[$event->status] ?? $event->status }} ({{ $event->happened_at?->format('Y-m-d') }})</option>
                                @endforeach
                            </select>
                            @error('corrects_event_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="ev-note">{{ __('Note') }}</label>
                            <input id="ev-note" type="text" name="note" class="form-control @error('note') is-invalid @enderror" value="{{ old('note') }}" maxlength="2000" aria-describedby="ev-help">
                            @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text" id="ev-help">{{ __('A status only moves forward. To go back, choose the event this one corrects.') }}</div>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Record status') }}</button>
                </form>
            @endcan
        </x-section>
    </div>

    <div class="col-lg-5 d-grid gap-3">
        <x-section :title="__('Fill')" icon="speedometer2">
            <div class="app-fu-gauges">
                @include('containers._fill', ['percent' => $row->fill_percent, 'loaded' => $row->loaded_cbm, 'capacity' => $row->capacity_cbm, 'unit' => 'CBM'])
                @include('containers._fill', ['percent' => $row->fill_kg_percent, 'loaded' => $row->loaded_kg, 'capacity' => $row->capacity_kg, 'unit' => 'KG'])
            </div>
            @if($row->capacity_cbm === null || $row->capacity_kg === null)
                <p class="form-text mt-3 mb-0">{{ __('This container type has no capacity set on the Lists & options screen.') }}</p>
            @endif
        </x-section>

        <x-section :title="__('Details')" icon="info-circle">
            <x-detail-list :cols="2" :items="$detailItems" />
            @if($container->notes)
                <div class="mt-3">
                    <div class="app-fu-summary__label mb-1">{{ __('Notes') }}</div>
                    <div style="white-space:pre-line">{{ $container->notes }}</div>
                </div>
            @endif
        </x-section>
    </div>
</div>

{{-- What is inside, net of unloads, and the load form. --}}
<x-section :title="__('Loaded in this container')" icon="boxes" flush class="mb-3"
           :subtitle="trans_choice('{0} Nothing loaded yet|{1} :count line|[2,*] :count lines', $contents->count(), ['count' => $contents->count()])">
    @error('supplier_order_line_id')<div class="alert alert-danger m-3 mb-0" role="alert">{{ $message }}</div>@enderror
    @error('cartons')<div class="alert alert-danger m-3 mb-0" role="alert">{{ $message }}</div>@enderror
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Item') }}</th><th>{{ __('Client / PI') }}</th><th>{{ __('Supplier order') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('CBM') }}</th><th class="text-end">{{ __('KG') }}</th>
                @can('containers.edit')<th><span class="visually-hidden">{{ __('Actions') }}</span></th>@endcan
            </tr></thead>
            <tbody>
            @forelse($contents as $line)
                @php
                    $photoUrl = $line->product_id && $line->product_photo ? $link('products.photo', $line->product_id) : null;
                    $piUrl = $link('proforma-invoices.show', $line->proforma_invoice_id);
                    $orderUrl = $link('supplier-orders.show', $line->supplier_order_id);
                    $clientUrl = $link('clients.show', $line->client_id);
                @endphp
                <tr class="app-fu-hasform">
                    <td style="white-space:normal; min-inline-size:14rem">
                        <div class="d-flex align-items-center gap-2">
                            @if($photoUrl)<img src="{{ $photoUrl }}" alt="" class="app-thumb" loading="lazy" onerror="this.style.display='none'">@endif
                            <div><span class="fw-semibold">{{ $line->product_name }}</span>@if($line->description)<div class="app-fu-sub">{{ $line->description }}</div>@endif</div>
                        </div>
                    </td>
                    <td class="app-fu-wrap" style="min-inline-size:9rem">@if($clientUrl)<a href="{{ $clientUrl }}">{{ $line->client_name }}</a>@else{{ $line->client_name }}@endif
                        <div class="app-fu-sub">@if($piUrl)<a href="{{ $piUrl }}" class="app-ltr">{{ $line->pi_number }}</a>@else<span class="app-ltr">{{ $line->pi_number }}</span>@endif</div></td>
                    <td>@if($orderUrl)<a href="{{ $orderUrl }}" class="app-ltr">{{ $line->supplier_order_number }}</a>@else<span class="app-ltr">{{ $line->supplier_order_number }}</span>@endif
                        @if($seesSuppliers && $line->supplier_name)<div class="app-fu-sub">{{ $line->supplier_name }}</div>@endif</td>
                    <td class="app-num text-end">{{ $num($line->cartons) }}</td>
                    <td class="app-num text-end">{{ $num($line->qty) }}</td>
                    <td class="app-num text-end">{{ $num($line->cbm) }}</td>
                    <td class="app-num text-end">{{ $num($line->kg) }}</td>
                    @can('containers.edit')
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-light text-danger" data-bs-toggle="collapse" data-bs-target="#unload-{{ $line->supplier_order_line_id }}" aria-controls="unload-{{ $line->supplier_order_line_id }}" aria-expanded="false">
                                <i class="bi bi-box-arrow-up" aria-hidden="true"></i>{{ __('Unload') }}
                            </button>
                        </td>
                    @endcan
                </tr>
                @can('containers.edit')
                    <tr class="app-fu-formrow collapse" id="unload-{{ $line->supplier_order_line_id }}">
                        <td colspan="8">
                            <form method="POST" action="{{ route('containers.unloads.store', $container) }}" class="app-fu-formrow__inner">
                                @csrf
                                <input type="hidden" name="supplier_order_line_id" value="{{ $line->supplier_order_line_id }}">
                                <div class="app-fu-panel-title">{{ __('Take cartons out of this container') }}</div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-6 col-md-3">
                                        <label class="form-label" for="u{{ $line->supplier_order_line_id }}-cartons">{{ __('Cartons') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="u{{ $line->supplier_order_line_id }}-cartons" type="number" name="cartons" class="form-control form-control-sm" min="0" step="any" value="{{ \App\Services\Shipping\Num::plain($line->cartons) }}" required dir="ltr">
                                    </div>
                                    <div class="col-6 col-md-3">
                                        <label class="form-label" for="u{{ $line->supplier_order_line_id }}-date">{{ __('Date') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                        <input id="u{{ $line->supplier_order_line_id }}-date" type="date" name="unloaded_on" class="form-control form-control-sm" value="{{ now()->toDateString() }}" required>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <div class="app-fu-formactions">
                                            <button class="btn btn-sm btn-danger"><i class="bi bi-box-arrow-up" aria-hidden="true"></i>{{ __('Unload') }}</button>
                                            <button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#unload-{{ $line->supplier_order_line_id }}">{{ __('Cancel') }}</button>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endcan
            @empty
                <tr><td colspan="8" class="p-0"><x-empty icon="box-seam" :title="__('Nothing loaded yet.')" :text="$canEdit ? __('Load cartons below once the goods are received.') : null" /></td></tr>
            @endforelse
            </tbody>
            @if($contents->isNotEmpty())
                <tfoot><tr class="fw-semibold">
                    <td colspan="3">{{ __('Total') }}</td>
                    <td class="app-num text-end">{{ $num($totals->cartons) }}</td>
                    <td class="app-num text-end">{{ $num($totals->qty) }}</td>
                    <td class="app-num text-end">{{ $num($totals->cbm) }}</td>
                    <td class="app-num text-end">{{ $num($totals->kg) }}</td>
                    @can('containers.edit')<td></td>@endcan
                </tr></tfoot>
            @endif
        </table>
    </div>
    @can('containers.edit')
        <form method="POST" action="{{ route('containers.loads.store', $container) }}" class="app-fu-foot">
            @csrf
            <div class="app-fu-foot__title"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>{{ __('Load cartons') }}</div>
            @if($loadable->isEmpty())
                <p class="app-fu-sub mb-0">{{ __('Nothing to load: book an order on this shipment and receive its goods first.') }}</p>
            @else
                <div class="row g-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label" for="load-line">{{ __('Order line') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <select id="load-line" name="supplier_order_line_id" class="form-select" required>
                            @foreach($loadable as $l)
                                <option value="{{ $l->supplier_order_line_id }}" @selected((string) old('supplier_order_line_id') === (string) $l->supplier_order_line_id)>
                                    {{ $l->supplier_order_number }} · {{ $l->client_name }} · {{ $l->pi_number }} · {{ \Illuminate\Support\Str::limit($l->product_name ?: $l->description, 40) }} — {{ __(':count cartons available', ['count' => $num($l->available)]) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="load-cartons">{{ __('Cartons') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <input id="load-cartons" type="number" name="cartons" class="form-control" min="0" step="any" value="{{ old('cartons') }}" required dir="ltr">
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label" for="load-date">{{ __('Date') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <input id="load-date" type="date" name="loaded_on" class="form-control @error('loaded_on') is-invalid @enderror" value="{{ old('loaded_on', now()->toDateString()) }}" required>
                        @error('loaded_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-2">
                        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-down" aria-hidden="true"></i>{{ __('Load') }}</button>
                    </div>
                </div>
            @endif
        </form>
    @endcan
</x-section>

@include('partials.attachments', ['attachable' => $container, 'type' => 'containers'])
<p class="app-fu-sub mt-2">{{ __('Attach the B/L, the packing list, the commercial invoice, the certificate of origin and loading photos here.') }}</p>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('tr.app-fu-formrow').forEach((row) => {
            const owner = row.previousElementSibling;
            row.addEventListener('show.bs.collapse', () => owner?.classList.add('is-open'));
            row.addEventListener('hidden.bs.collapse', () => owner?.classList.remove('is-open'));
        });
        document.querySelector('form .is-invalid')?.focus();
    });
</script>
@endpush
