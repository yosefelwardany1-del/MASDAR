@extends('layouts.app')

@section('title', __('Shipments'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Shipments') }}</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('containers.index') }}" class="btn btn-light"><i class="bi bi-boxes me-1"></i>{{ __('Container board') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New shipment') }}</a>
        @endcan
    </div>
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <input type="search" name="search" value="{{ $search }}" class="form-control" style="max-inline-size:280px" placeholder="{{ __('Search by number, booking, B/L or vessel') }}">
    <select name="shipping_company_id" class="form-select" style="max-inline-size:240px" onchange="this.form.submit()">
        <option value="">{{ __('All shipping companies') }}</option>
        @foreach($companies as $company)
            <option value="{{ $company->id }}" @selected($companyId === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">{{ __('Search') }}</button>
</form>

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Shipment') }}</th><th>{{ __('Shipping company') }}</th><th>{{ __('Mode') }}</th>
                <th>{{ __('Booking no.') }}</th><th>{{ __('Route') }}</th><th>{{ __('ETD') }}</th><th>{{ __('ETA') }}</th>
                <th>{{ __('Containers') }}</th><th>{{ __('Orders') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($shipments as $s)
                <tr>
                    <td><a href="{{ route('shipments.show', $s) }}" class="app-ltr fw-semibold">{{ $s->number }}</a></td>
                    <td>{{ $s->shippingCompany?->name ?? '—' }}</td>
                    <td>{{ $s->mode ? ($modes[$s->mode] ?? $s->mode) : '—' }}</td>
                    <td><span class="app-ltr">{{ $s->booking_no ?: '—' }}</span></td>
                    <td>@include('containers._route', ['pol' => $s->port_of_loading, 'pod' => $s->port_of_discharge])</td>
                    <td class="app-num">{{ $s->etd?->toDateString() ?? '—' }}</td>
                    <td class="app-num">{{ $s->eta?->toDateString() ?? '—' }}</td>
                    <td class="app-num">{{ $s->containers_count }}</td>
                    <td class="app-num">{{ $s->bookings_count }}</td>
                    <td class="text-end">
                        <a href="{{ route('shipments.show', $s) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        @can('containers.edit')
                            <a href="{{ route('shipments.edit', $s) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="app-empty"><i class="bi bi-truck"></i>{{ __('No shipments yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($shipments->hasPages())<div class="p-3">{{ $shipments->links() }}</div>@endif
</div>
@endsection
