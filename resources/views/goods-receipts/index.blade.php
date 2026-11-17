@extends('layouts.app')

@section('title', __('Goods receipt & inspection'))

@use('App\Services\SupplierOrders\Quantity')

@section('content')
@php
    $canRecord = auth()->user()->can('goods_receipts.edit');
    $results = ['pass' => __('Pass'), 'fail' => __('Fail'), 'pass_with_notes' => __('Pass with notes')];
    $resultTone = ['pass' => 'success', 'fail' => 'danger', 'pass_with_notes' => 'warning'];
    $orderLink = fn ($order) => $canSeeOrders
        ? '<a href="'.e(route('supplier-orders.show', $order)).'" class="app-ltr">'.e($order->number).'</a>'
        : '<span class="app-ltr">'.e($order->number).'</span>';
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">{{ __('Goods receipt & inspection') }}</h1>
    @can('supplier_orders.view')
        <a href="{{ route('supplier-orders.index') }}" class="btn btn-light"><i class="bi bi-kanban me-1"></i>{{ __('Order follow-up') }}</a>
    @endcan
</div>
<p class="text-muted small mb-3">{{ __('Examine & receive goods & update in the system: record the cartons that came in for each line, with the inspection result and what was measured.') }}</p>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    @if($seeSuppliers)
        <select name="supplier" class="form-select" style="max-inline-size:240px">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected($supplier === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="order" class="form-select" style="max-inline-size:220px">
        <option value="">{{ __('All open orders') }}</option>
        @foreach($orders as $o)
            <option value="{{ $o->id }}" @selected($orderId === $o->id)>{{ $o->number }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">{{ __('Filter') }}</button>
</form>

<div class="app-card mb-4">
    <h2 class="h6 p-3 pb-0 mb-0">{{ __('Awaiting goods') }}</h2>
    <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Order no.') }}</th><th>{{ __('Factory / supplier') }}</th><th>{{ __('Client / PI') }}</th><th></th><th>{{ __('Item') }}</th>
                <th>{{ __('Delivery date') }}</th>
                <th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Received') }}</th><th class="text-end">{{ __('Awaiting') }}</th>
                @if($canRecord)<th style="min-inline-size:18rem"></th>@endif
            </tr></thead>
            <tbody>
            @forelse($awaiting as $line)
                @php
                    $order = $line->supplierOrder;
                    $pi = $line->piLine?->proformaInvoice;
                    $received = (string) ($line->received_cartons ?? '0');
                    $outstanding = bcsub((string) $line->cartons, $received, 4);
                @endphp
                <tr>
                    <td>{!! $orderLink($order) !!}</td>
                    <td>{{ $seeSuppliers ? $order->supplier?->name : '—' }}</td>
                    <td>{{ $pi?->client?->name }} · <span class="app-ltr">{{ $pi?->reference() }}</span></td>
                    <td>@include('supplier-orders._photo', ['product' => $line->piLine?->product])</td>
                    <td style="white-space:normal; min-inline-size:12rem">
                        <div class="fw-semibold">{{ $line->piLine?->product?->name }}</div>
                        <div class="text-muted small">{{ $line->piLine?->description }}</div>
                    </td>
                    <td class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($received) }}</td>
                    <td class="app-num text-end fw-semibold">{{ Quantity::trim($outstanding) }}</td>
                    @if($canRecord)
                        <td>
                            <details @if((int) old('supplier_order_line_id') === $line->id) open @endif>
                                <summary class="btn btn-sm btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>{{ __('Receive') }}</summary>
                                <form method="POST" action="{{ route('goods-receipts.store') }}" class="row g-2 mt-1">
                                    @csrf
                                    <input type="hidden" name="supplier_order_line_id" value="{{ $line->id }}">
                                    <div class="col-6">
                                        <label class="form-label small mb-1">{{ __('Date') }}</label>
                                        <input type="date" name="received_on" required max="{{ now()->toDateString() }}" class="form-control form-control-sm" value="{{ now()->toDateString() }}">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">{{ __('Cartons') }}</label>
                                        <input type="number" name="cartons" required step="0.0001" min="0.0001" class="form-control form-control-sm" dir="ltr" value="{{ Quantity::trim($outstanding) }}">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">{{ __('Inspection result') }}</label>
                                        <select name="inspection_result" required class="form-select form-select-sm">
                                            @foreach($results as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">{{ __('Measured CBM') }}</label>
                                        <input type="number" name="measured_cbm" step="0.0001" min="0" class="form-control form-control-sm" dir="ltr">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small mb-1">{{ __('Measured KG') }}</label>
                                        <input type="number" name="measured_kg" step="0.0001" min="0" class="form-control form-control-sm" dir="ltr">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">{{ __('Location') }}</label>
                                        <input name="location" maxlength="120" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">{{ __('Note') }}</label>
                                        <input name="note" maxlength="2000" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-12"><button class="btn btn-sm btn-primary">{{ __('Record receipt') }}</button></div>
                                </form>
                            </details>
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canRecord ? 10 : 9 }}" class="app-empty">{{ __('No open order line is awaiting goods.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($awaiting->hasPages())<div class="p-3">{{ $awaiting->links() }}</div>@endif
</div>

<div class="app-card">
    <h2 class="h6 p-3 pb-0 mb-0">{{ __('Recent receipts') }}</h2>
    <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Date') }}</th><th>{{ __('Order no.') }}</th><th>{{ __('Item') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th>{{ __('Inspection') }}</th>
                <th class="text-end">{{ __('CBM') }}</th><th class="text-end">{{ __('KG') }}</th><th>{{ __('Location') }}</th><th>{{ __('Note') }}</th><th>{{ __('By') }}</th>
                @if($canRecord)<th style="min-inline-size:14rem"></th>@endif
            </tr></thead>
            <tbody>
            @forelse($recent as $receipt)
                @php $held = bcadd((string) $receipt->cartons, (string) ($receipt->corrected_cartons ?? '0'), 4); @endphp
                <tr>
                    <td class="app-num">{{ $receipt->received_on?->format('Y-m-d') }}</td>
                    <td>{!! $orderLink($receipt->line->supplierOrder) !!}</td>
                    <td style="white-space:normal">{{ $receipt->line->piLine?->product?->name }}</td>
                    <td class="app-num text-end {{ $receipt->isCorrection() ? 'text-danger' : '' }}">{{ Quantity::trim($receipt->cartons) }}</td>
                    <td>
                        @if($receipt->isCorrection())
                            <span class="small text-muted">{{ __('Correction of the receipt of :date', ['date' => $receipt->corrects?->received_on?->format('Y-m-d')]) }}</span>
                        @else
                            @php $tone = $resultTone[$receipt->inspection_result] ?? 'secondary'; @endphp
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}-emphasis">{{ $results[$receipt->inspection_result] ?? $receipt->inspection_result }}</span>
                        @endif
                    </td>
                    <td class="app-num text-end">{{ $receipt->measured_cbm !== null ? Quantity::trim($receipt->measured_cbm) : '' }}</td>
                    <td class="app-num text-end">{{ $receipt->measured_kg !== null ? Quantity::trim($receipt->measured_kg) : '' }}</td>
                    <td>{{ $receipt->location }}</td>
                    <td class="small" style="white-space:normal">{{ $receipt->note }}</td>
                    <td>{{ $receipt->user?->name }}</td>
                    @if($canRecord)
                        <td>
                            @if(! $receipt->isCorrection() && bccomp($held, '0', 4) > 0)
                                <details>
                                    <summary class="btn btn-sm btn-light"><i class="bi bi-arrow-return-left me-1"></i>{{ __('Correct') }}</summary>
                                    <form method="POST" action="{{ route('goods-receipts.correct', $receipt) }}" class="mt-2">
                                        @csrf
                                        <label class="form-label small mb-1">{{ __('Cartons to take off') }}</label>
                                        <input type="number" name="cartons" required step="0.0001" min="0.0001" class="form-control form-control-sm mb-2" dir="ltr">
                                        <input name="note" required maxlength="2000" class="form-control form-control-sm mb-2" placeholder="{{ __('Reason') }}">
                                        <button class="btn btn-sm btn-light">{{ __('Save correction') }}</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $canRecord ? 11 : 10 }}" class="app-empty">{{ __('No goods received yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
