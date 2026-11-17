@extends('layouts.app')

@section('title', __('Container follow-up'))

@section('content')
@php
    $link = fn (string $name, $param) => \Illuminate\Support\Facades\Route::has($name) ? route($name, $param) : null;
    $queries = app(\App\Services\Shipping\ShippingQueries::class);
    $comma = app()->getLocale() === 'ar' ? '، ' : ', ';
    $filtered = (bool) array_filter($filters);
    $flags = [];
    foreach ($containers as $c) {
        $flags[$c->id] = $queries->etaFlag($c->eta, $c->status_sort, $arrivedSort);
    }
    $lateCount = collect($flags)->filter(fn ($f) => isset($f['late']))->count();
    $soonCount = collect($flags)->filter(fn ($f) => isset($f['soon']))->count();
@endphp

<x-page-header :title="__('Container follow-up')" :subtitle="__('Where every container is, what is inside, how full it is and when it lands.')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-boxes" aria-hidden="true"></i>{{ trans_choice('{0} No containers|{1} :count container|[2,*] :count containers', $containers->total(), ['count' => $containers->total()]) }}</span>
        @if($lateCount)<span class="app-pill app-pill--late"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{1} :count late on this page|[2,*] :count late on this page', $lateCount, ['count' => $lateCount]) }}</span>@endif
        @if($soonCount)<span class="app-pill app-pill--due"><i class="bi bi-clock" aria-hidden="true"></i>{{ trans_choice('{1} :count arriving soon|[2,*] :count arriving soon', $soonCount, ['count' => $soonCount]) }}</span>@endif
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('shipments.index') }}" class="btn btn-light"><i class="bi bi-truck" aria-hidden="true"></i>{{ __('Shipments') }}</a>
        @can('containers.edit')
            <a href="{{ route('shipments.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New shipment') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="d-flex flex-wrap gap-2 align-items-center mb-3 app-fu-filters" aria-label="{{ __('Filter') }}">
    <select name="status" class="form-select" onchange="this.form.submit()" aria-label="{{ __('Status') }}">
        <option value="">{{ __('All statuses') }}</option>
        <option value="_none" @selected($filters['status'] === '_none')>{{ __('No status yet') }}</option>
        @foreach($statusOptions as $code => $name)
            <option value="{{ $code }}" @selected($filters['status'] === (string) $code)>{{ $name }}</option>
        @endforeach
    </select>
    <select name="shipping_company_id" class="form-select" onchange="this.form.submit()" aria-label="{{ __('Shipping company') }}">
        <option value="">{{ __('All shipping companies') }}</option>
        @foreach($companies as $company)
            <option value="{{ $company->id }}" @selected($filters['shipping_company_id'] === $company->id)>{{ $company->name }}</option>
        @endforeach
    </select>
    <select name="client_id" class="form-select" onchange="this.form.submit()" aria-label="{{ __('Client') }}">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clientOptions as $client)
            <option value="{{ $client->id }}" @selected($filters['client_id'] === $client->id)>{{ $client->name }}</option>
        @endforeach
    </select>
    <div class="form-check form-switch ms-1">
        <input class="form-check-input" type="checkbox" role="switch" name="soon" value="1" id="f-soon" @checked($filters['soon']) onchange="this.form.submit()">
        <label class="form-check-label" for="f-soon">{{ trans_choice('{1} Arriving within one day|[2,*] Arriving within :count days', max($warningDays, 1), ['count' => $warningDays]) }}</label>
    </div>
    <div class="form-check form-switch">
        <input class="form-check-input" type="checkbox" role="switch" name="late" value="1" id="f-late" @checked($filters['late']) onchange="this.form.submit()">
        <label class="form-check-label" for="f-late">{{ __('Late') }}</label>
    </div>
    <noscript><button class="btn btn-light">{{ __('Filter') }}</button></noscript>
    @if($filtered)
        <a href="{{ route('containers.index') }}" class="btn btn-sm btn-link">{{ __('Clear filters') }}</a>
    @endif
</form>

@if($containers->isEmpty())
    <div class="app-card">
        <x-empty icon="boxes" :title="__('No containers match.')" :text="$filtered ? __('Try other filters, or clear them to see every container.') : __('Containers are added to a shipment, then appear here.')">
            @if($filtered)
                <x-slot:action><a href="{{ route('containers.index') }}" class="btn btn-light btn-sm">{{ __('Clear filters') }}</a></x-slot:action>
            @endif
        </x-empty>
    </div>
@else
    {{-- Phone: one card per container. --}}
    <ul class="app-fu-cards d-md-none" aria-label="{{ __('Containers') }}">
        @foreach($containers as $c)
            @php $flag = $flags[$c->id]; @endphp
            <li class="app-fu-card @if(isset($flag['late'])) is-late @endif">
                <div class="app-fu-card__top">
                    <a href="{{ route('containers.show', $c) }}" class="app-fu-order__no"><span class="app-ltr">{{ $c->container_no ?: '#'.$c->id }}</span></a>
                    <span class="app-fu-sub">{{ $c->container_type ? ($types[$c->container_type] ?? $c->container_type) : '' }}</span>
                    <span class="app-fu-card__aside">@include('containers._status', ['status' => $c->status, 'statusSort' => $c->status_sort])</span>
                </div>
                <div class="app-fu-phases app-fu-mini">@include('containers._phases', ['statusSort' => $c->status_sort, 'sorts' => $statusSorts, 'flag' => $flag, 'mode' => $c->mode, 'etd' => $c->etd, 'eta' => $c->eta])</div>
                <div class="app-fu-card__foot">
                    <span class="app-fu-sub">@include('containers._route', ['pol' => $c->port_of_loading, 'pod' => $c->port_of_discharge])</span>
                    <span class="app-fu-sub">{{ __('ETA') }} <span class="app-num app-fu-strong">{{ $c->eta ?: '—' }}</span></span>
                </div>
                @include('containers._eta', ['flag' => $flag])
                @include('containers._fill', ['percent' => $c->fill_percent, 'loaded' => $c->loaded_cbm, 'capacity' => $c->capacity_cbm, 'unit' => 'CBM', 'compact' => true])
                <div class="app-fu-sub"><i class="bi bi-person" aria-hidden="true"></i> {{ collect($clients[$c->id] ?? [])->pluck('name')->join($comma) ?: '—' }}</div>
            </li>
        @endforeach
    </ul>

    {{-- Tablet and desktop: the board; the container column stays in view. --}}
    <div class="app-card d-none d-md-block">
        <div class="table-responsive app-board app-fu-board">
            <table class="table align-middle mb-0 table-hover">
                <caption class="visually-hidden">{{ __('Container follow-up') }}</caption>
                <thead><tr>
                    <th scope="col">{{ __('Container') }}</th>
                    <th scope="col">{{ __('Status') }}</th>
                    <th scope="col">{{ __('Route') }} · {{ __('ETA') }}</th>
                    <th scope="col">{{ __('Fill') }}</th>
                    <th scope="col">{{ __('Clients') }}</th>
                    <th scope="col">{{ __('Shipping company') }}</th>
                    <th scope="col" class="text-center"><i class="bi bi-paperclip" title="{{ __('Attachments') }}" aria-hidden="true"></i><span class="visually-hidden">{{ __('Attachments') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($containers as $c)
                    @php $flag = $flags[$c->id]; @endphp
                    <tr @class(['app-fu-row--late' => isset($flag['late'])])>
                        <th scope="row" class="fw-normal" style="min-inline-size:11rem">
                            <div class="app-fu-order">
                                <a href="{{ route('containers.show', $c) }}" class="app-fu-order__no"><span class="app-ltr">{{ $c->container_no ?: '#'.$c->id }}</span></a>
                                <div class="app-fu-order__line">{{ $c->container_type ? ($types[$c->container_type] ?? $c->container_type) : '—' }}</div>
                                <div class="app-fu-order__line"><i class="bi bi-truck" aria-hidden="true"></i><span class="visually-hidden">{{ __('Shipment') }}:</span> <a href="{{ route('shipments.show', $c->shipment_id) }}" class="app-ltr">{{ $c->shipment_number }}</a></div>
                            </div>
                        </th>
                        <td style="min-inline-size:12rem">
                            <div class="app-fu-phases app-fu-mini">@include('containers._phases', ['statusSort' => $c->status_sort, 'sorts' => $statusSorts, 'flag' => $flag, 'mode' => $c->mode, 'etd' => $c->etd, 'eta' => $c->eta])</div>
                            <div class="app-fu-statusline">@include('containers._status', ['status' => $c->status, 'statusSort' => $c->status_sort])</div>
                        </td>
                        <td>
                            <div>@include('containers._route', ['pol' => $c->port_of_loading, 'pod' => $c->port_of_discharge])</div>
                            <div class="app-fu-sub mt-1">{{ __('ETD') }} <span class="app-num">{{ $c->etd ?: '—' }}</span></div>
                            <div class="app-fu-sub">{{ __('ETA') }} <span class="app-num app-fu-strong">{{ $c->eta ?: '—' }}</span></div>
                            @if($flag)<div class="mt-1">@include('containers._eta', ['flag' => $flag])</div>@endif
                        </td>
                        <td style="min-inline-size:11rem">
                            @include('containers._fill', ['percent' => $c->fill_percent, 'loaded' => $c->loaded_cbm, 'capacity' => $c->capacity_cbm, 'unit' => 'CBM', 'compact' => true,
                                'kg' => ['loaded' => $c->loaded_kg, 'capacity' => $c->capacity_kg]])
                        </td>
                        <td class="app-fu-wrap" style="min-inline-size:9rem">
                            @forelse($clients[$c->id] ?? [] as $client)
                                @php $clientUrl = $link('clients.show', $client->id); @endphp
                                <div>@if($clientUrl)<a href="{{ $clientUrl }}">{{ $client->name }}</a>@else{{ $client->name }}@endif</div>
                            @empty
                                <span class="text-muted">—</span>
                            @endforelse
                        </td>
                        <td>
                            @php $companyUrl = $c->shipping_company_id ? $link('shipping-companies.show', $c->shipping_company_id) : null; @endphp
                            @if($companyUrl)<a href="{{ $companyUrl }}">{{ $c->shipping_company_name }}</a>@else{{ $c->shipping_company_name ?? '—' }}@endif
                            <div class="app-fu-sub">{{ $c->mode ? ($modes[$c->mode] ?? $c->mode) : '—' }}</div>
                        </td>
                        <td class="app-num text-center">
                            @if($c->attachments_count > 0)<span class="app-pill"><i class="bi bi-paperclip" aria-hidden="true"></i>{{ $c->attachments_count }}</span>@else<span class="text-muted">0</span>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@if($containers->hasPages())<div class="mt-3">{{ $containers->links() }}</div>@endif
@endsection
