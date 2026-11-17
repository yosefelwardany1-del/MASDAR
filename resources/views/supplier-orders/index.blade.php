@extends('layouts.app')

@section('title', __('Order follow-up'))

@section('content')
@php
    $canSeePis = auth()->user()->can('proforma_invoices.view') && Route::has('proforma-invoices.show');
    $canSeeSupplierPage = auth()->user()->can('suppliers.view') && Route::has('suppliers.show');
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">{{ __('Order follow-up') }}</h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('supplier-orders.evaluation') }}" class="btn btn-light"><i class="bi bi-bar-chart me-1"></i>{{ __('Supplier evaluation') }}</a>
        @can('goods_receipts.view')
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-light"><i class="bi bi-clipboard-check me-1"></i>{{ __('Goods receipt & inspection') }}</a>
        @endcan
    </div>
</div>
<p class="text-muted small mb-3">{{ __('Orders management, observation, and evaluation: one row per supplier order, one column per step. Late rows come first.') }}</p>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center">
    <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" style="max-inline-size:200px" placeholder="{{ __('Order number') }}" dir="ltr">
    <select name="status" class="form-select" style="max-inline-size:170px">
        @foreach(['open' => __('Open'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'), 'all' => __('All statuses')] as $value => $label)
            <option value="{{ $value }}" @selected(($filters['status'] ?? 'open') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @if($seeSuppliers)
        <select name="supplier" class="form-select" style="max-inline-size:220px">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected((int) ($filters['supplier'] ?? 0) === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="client" class="form-select" style="max-inline-size:220px">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clients as $c)
            <option value="{{ $c->id }}" @selected((int) ($filters['client'] ?? 0) === $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <select name="merchandiser" class="form-select" style="max-inline-size:200px">
        <option value="">{{ __('All merchandisers') }}</option>
        @foreach($merchandisers as $m)
            <option value="{{ $m->id }}" @selected((int) ($filters['merchandiser'] ?? 0) === $m->id)>{{ $m->name }}</option>
        @endforeach
    </select>
    <div class="form-check ms-1">
        <input class="form-check-input" type="checkbox" name="late" value="1" id="late-only" @checked(! empty($filters['late']))>
        <label class="form-check-label" for="late-only">{{ __('Late only') }}</label>
    </div>
    <button class="btn btn-light">{{ __('Filter') }}</button>
</form>

<div class="d-flex flex-wrap gap-2 mb-2 small text-muted align-items-center">
    <span>{{ __('Key:') }}</span>
    <span class="app-pill app-pill--done"><i class="bi bi-check2"></i>{{ __('Done') }}</span>
    <span class="app-pill app-pill--due"><i class="bi bi-clock"></i>{{ __('Due soon') }}</span>
    <span class="app-pill app-pill--late"><i class="bi bi-exclamation-triangle"></i>{{ __('Late') }}</span>
    <span class="app-pill app-pill--skip"><i class="bi bi-dash"></i>{{ __('Not applicable') }}</span>
    <span class="app-pill">{{ __('Open') }}</span>
</div>

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr>
                <th>{{ __('Factory / supplier') }}</th>
                <th>{{ __('Order no.') }}</th>
                <th>{{ __('Client / PI') }}</th>
                <th>{{ __('Confirmation date') }}</th>
                <th>{{ __('Delivery date') }}</th>
                @foreach($steps as $step)
                    <th class="text-center" style="white-space:normal; min-inline-size:7rem; max-inline-size:9rem">{{ $step->displayName() }}</th>
                @endforeach
            </tr></thead>
            <tbody>
            @forelse($orders as $order)
                <tr>
                    <td>
                        @if($seeSuppliers)
                            @if($canSeeSupplierPage)
                                <a href="{{ route('suppliers.show', $order->supplier_id) }}">{{ $order->supplier?->name }}</a>
                            @else
                                {{ $order->supplier?->name }}
                            @endif
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        <a href="{{ route('supplier-orders.show', $order) }}" class="fw-semibold"><span class="app-ltr">{{ $order->number }}</span></a>
                        @if($order->is_late)<i class="bi bi-exclamation-circle-fill text-danger ms-1" title="{{ __('Has late steps') }}"></i>@endif
                        @if($order->status !== 'open')<div>@include('supplier-orders._status', ['status' => $order->status])</div>@endif
                        @if($order->merchandiser)<div class="text-muted small">{{ $order->merchandiser->name }}</div>@endif
                    </td>
                    <td>
                        @forelse($orderClients[$order->id] ?? [] as $row)
                            <div>{{ $row->client_name }} ·
                                @if($canSeePis)
                                    <a href="{{ route('proforma-invoices.show', $row->pi_id) }}" class="app-ltr">{{ $row->pi_reference }}</a>
                                @else
                                    <span class="app-ltr">{{ $row->pi_reference }}</span>
                                @endif
                            </div>
                        @empty
                            —
                        @endforelse
                    </td>
                    <td class="app-num">{{ $order->confirmation_date?->format('Y-m-d') ?? '—' }}</td>
                    <td>
                        <span class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                        @isset($original[$order->id])
                            <i class="bi bi-calendar-x text-warning ms-1"
                               title="{{ __('Moved: first promised :date, changed :count times', ['date' => $original[$order->id]['date']?->format('Y-m-d') ?? '—', 'count' => $original[$order->id]['changes']]) }}"
                               aria-label="{{ __('Delivery date moved') }}"></i>
                        @endisset
                    </td>
                    @foreach($steps as $step)
                        <td class="text-center">@include('supplier-orders._pill', ['state' => $states[$order->id][$step->id]])</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ 5 + $steps->count() }}" class="app-empty">{{ __('No supplier orders found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())<div class="p-3">{{ $orders->links() }}</div>@endif
</div>
@endsection
