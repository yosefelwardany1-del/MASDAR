@extends('layouts.app')

@section('title', __('Container follow-up'))

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $queries = app(\App\Services\Shipping\ShippingQueries::class);
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Container follow-up') }}</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('shipments.index') }}" class="btn btn-light"><i class="bi bi-truck me-1"></i>{{ __('Shipments') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New shipment') }}</a>
        @endcan
    </div>
</div>

<form method="GET" class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <select name="status" class="form-select" style="max-inline-size:220px" onchange="this.form.submit()">
        <option value="">{{ __('All statuses') }}</option>
        <option value="_none" @selected($filters['status'] === '_none')>{{ __('No status yet') }}</option>
        @foreach($statusOptions as $code => $name)
            <option value="{{ $code }}" @selected($filters['status'] === (string) $code)>{{ $name }}</option>
        @endforeach
    </select>
    <select name="shipping_company_id" class="form-select" style="max-inline-size:220px" onchange="this.form.submit()">
        <option value="">{{ __('All shipping companies') }}</option>
        @foreach($companies as $company)
            <option value="{{ $company->id }}" @selected($filters['shipping_company_id'] === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
    <select name="client_id" class="form-select" style="max-inline-size:220px" onchange="this.form.submit()">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clientOptions as $client)
            <option value="{{ $client->id }}" @selected($filters['client_id'] === $client->id)>{{ $client->name }}</option>
        @endforeach
    </select>
    <div class="form-check form-check-inline ms-1">
        <input class="form-check-input" type="checkbox" name="soon" value="1" id="f-soon" @checked($filters['soon']) onchange="this.form.submit()">
        <label class="form-check-label" for="f-soon">{{ trans_choice('{1} Arriving within one day|[2,*] Arriving within :count days', max($warningDays, 1), ['count' => $warningDays]) }}</label>
    </div>
    <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" name="late" value="1" id="f-late" @checked($filters['late']) onchange="this.form.submit()">
        <label class="form-check-label" for="f-late">{{ __('Late') }}</label>
    </div>
    <noscript><button class="btn btn-light">{{ __('Filter') }}</button></noscript>
    @if(array_filter($filters))
        <a href="{{ route('containers.index') }}" class="btn btn-sm btn-link">{{ __('Clear filters') }}</a>
    @endif
</form>

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Container') }}</th><th>{{ __('Shipment') }}</th><th>{{ __('Shipping company') }}</th><th>{{ __('Mode') }}</th>
                <th>{{ __('Route') }}</th><th>{{ __('ETD') }}</th><th>{{ __('ETA') }}</th><th>{{ __('Status') }}</th>
                <th>{{ __('Clients') }}</th><th>{{ __('CBM') }}</th><th>{{ __('KG') }}</th><th>{{ __('Fill') }}</th>
                <th><i class="bi bi-paperclip" title="{{ __('Attachments') }}"></i></th>
            </tr></thead>
            <tbody>
            @forelse($containers as $c)
                @php $flag = $queries->etaFlag($c->eta, $c->status_sort, $arrivedSort); @endphp
                <tr @class(['table-danger' => isset($flag['late'])])>
                    <td>
                        <a href="{{ route('containers.show', $c) }}" class="app-ltr fw-semibold">{{ $c->container_no ?: '#'.$c->id }}</a>
                        <div class="small text-muted">{{ $c->container_type ? ($types[$c->container_type] ?? $c->container_type) : '—' }}</div>
                    </td>
                    <td><a href="{{ route('shipments.show', $c->shipment_id) }}" class="app-ltr">{{ $c->shipment_number }}</a></td>
                    <td>
                        @php $companyUrl = $c->shipping_company_id ? $link('shipping-companies.show', $c->shipping_company_id) : null; @endphp
                        @if($companyUrl)<a href="{{ $companyUrl }}">{{ $c->shipping_company_name }}</a>@else{{ $c->shipping_company_name ?? '—' }}@endif
                    </td>
                    <td>{{ $c->mode ? ($modes[$c->mode] ?? $c->mode) : '—' }}</td>
                    <td>@include('containers._route', ['pol' => $c->port_of_loading, 'pod' => $c->port_of_discharge])</td>
                    <td class="app-num">{{ $c->etd ?: '—' }}</td>
                    <td><span class="app-num">{{ $c->eta ?: '—' }}</span> @include('containers._eta', ['flag' => $flag])</td>
                    <td>@include('containers._status', ['status' => $c->status, 'statusSort' => $c->status_sort])</td>
                    <td>
                        @forelse($clients[$c->id] ?? [] as $client)
                            @php $clientUrl = $link('clients.show', $client->id); @endphp
                            <div>@if($clientUrl)<a href="{{ $clientUrl }}">{{ $client->name }}</a>@else{{ $client->name }}@endif</div>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </td>
                    <td class="app-num">{{ \App\Services\Shipping\Num::show($c->loaded_cbm) }}</td>
                    <td class="app-num">{{ \App\Services\Shipping\Num::show($c->loaded_kg) }}</td>
                    <td>@include('containers._fill', ['percent' => $c->fill_percent, 'loaded' => $c->loaded_cbm, 'capacity' => $c->capacity_cbm, 'unit' => 'CBM', 'compact' => true])</td>
                    <td class="app-num">{{ $c->attachments_count }}</td>
                </tr>
            @empty
                <tr><td colspan="13" class="app-empty"><i class="bi bi-boxes"></i>{{ __('No containers match.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($containers->hasPages())<div class="p-3">{{ $containers->links() }}</div>@endif
</div>
@endsection
