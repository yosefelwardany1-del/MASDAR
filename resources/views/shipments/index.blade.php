@extends('layouts.app')

@section('title', __('Shipments'))

@section('content')
<x-page-header :title="__('Shipments')" :subtitle="__('Bookings with shipping companies: their route, dates, containers and the orders booked on them.')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-truck" aria-hidden="true"></i>{{ trans_choice('{0} No shipments|{1} :count shipment|[2,*] :count shipments', $shipments->total(), ['count' => $shipments->total()]) }}</span>
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('containers.index') }}" class="btn btn-light"><i class="bi bi-boxes" aria-hidden="true"></i>{{ __('Container board') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New shipment') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center app-fu-filters" role="search" aria-label="{{ __('Search') }}">
    <div class="app-fu-search" style="max-inline-size:22rem; flex-basis:18rem">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="search" value="{{ $search }}" class="form-control" placeholder="{{ __('Search by number, booking, B/L or vessel') }}" aria-label="{{ __('Search by number, booking, B/L or vessel') }}">
    </div>
    <select name="shipping_company_id" class="form-select" onchange="this.form.submit()" aria-label="{{ __('Shipping company') }}">
        <option value="">{{ __('All shipping companies') }}</option>
        @foreach($companies as $company)
            <option value="{{ $company->id }}" @selected($companyId === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
    <button class="btn btn-primary"><i class="bi bi-search" aria-hidden="true"></i>{{ __('Search') }}</button>
    @if($search !== '' || $companyId)
        <a href="{{ route('shipments.index') }}" class="btn btn-link btn-sm">{{ __('Clear filters') }}</a>
    @endif
</form>

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th>{{ __('Shipment') }}</th><th>{{ __('Mode') }}</th>
                <th>{{ __('Booking no.') }}</th><th>{{ __('Route') }}</th><th>{{ __('ETD') }}</th><th>{{ __('ETA') }}</th>
                <th class="text-end">{{ __('Containers') }}</th><th class="text-end">{{ __('Orders') }}</th><th><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($shipments as $s)
                <tr>
                    <td>
                        <a href="{{ route('shipments.show', $s) }}" class="app-fu-order__no"><span class="app-ltr">{{ $s->number }}</span></a>
                        <div class="app-fu-sub">{{ $s->shippingCompany?->name ?? '—' }}</div>
                    </td>
                    <td>{{ $s->mode ? ($modes[$s->mode] ?? $s->mode) : '—' }}</td>
                    <td><span class="app-ltr">{{ $s->booking_no ?: '—' }}</span></td>
                    <td>@include('containers._route', ['pol' => $s->port_of_loading, 'pod' => $s->port_of_discharge])</td>
                    <td class="app-num">{{ $s->etd?->toDateString() ?? '—' }}</td>
                    <td class="app-num fw-semibold">{{ $s->eta?->toDateString() ?? '—' }}</td>
                    <td class="text-end"><span class="app-pill"><i class="bi bi-box-seam" aria-hidden="true"></i><span class="app-num">{{ $s->containers_count }}</span></span></td>
                    <td class="text-end"><span class="app-pill"><i class="bi bi-kanban" aria-hidden="true"></i><span class="app-num">{{ $s->bookings_count }}</span></span></td>
                    <td class="text-end">
                        <a href="{{ route('shipments.show', $s) }}" class="btn btn-sm btn-light" title="{{ __('View') }}" aria-label="{{ __('View') }} {{ $s->number }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        @can('containers.edit')
                            <a href="{{ route('shipments.edit', $s) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }} {{ $s->number }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="p-0">
                    <x-empty icon="truck" :title="__('No shipments yet.')" :text="__('A shipment is a booking with a shipping company; its containers and orders hang off it.')">
                        @can('containers.edit')
                            <x-slot:action><a href="{{ route('shipments.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New shipment') }}</a></x-slot:action>
                        @endcan
                    </x-empty>
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($shipments->hasPages())<div class="p-3 border-top">{{ $shipments->links() }}</div>@endif
</div>
@endsection
