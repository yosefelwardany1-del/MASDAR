@extends('layouts.app')

@section('title', __('Add lines to :number', ['number' => $order->number]))

@use('App\Services\SupplierOrders\Quantity')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">{{ __('Add lines to :number', ['number' => $order->number]) }}</h1>
    <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-light">{{ __('Back to the order') }}</a>
</div>
<p class="text-muted mb-3">
    {{ __('From :pi', ['pi' => $pi->reference()]) }} · {{ $pi->client?->name }} ·
    {{ __('Supplier') }}: {{ $seeSuppliers ? $order->supplier?->name : '—' }} · <span class="app-ltr">{{ $order->currency }}</span>
</p>

@if($errors->any())
    <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>
@endif

@if($lines->isEmpty())
    <div class="app-card p-4 text-muted">{{ __('Every line of this PI is already on a supplier order.') }}</div>
@else
<form method="POST" action="{{ route('supplier-orders.lines.store', $order) }}" class="app-card">
    @csrf
    <input type="hidden" name="pi" value="{{ $pi->id }}">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Add') }}</th><th>{{ __('No.') }}</th><th></th><th>{{ __('Item') }}</th><th>{{ __('Planned supplier') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                <th style="min-inline-size:9rem">{{ __("Supplier's unit price") }}</th>
            </tr></thead>
            <tbody>
            @foreach($lines as $line)
                @php $planned = (int) $line->supplier_id === (int) $order->supplier_id; @endphp
                <tr>
                    <td><input type="checkbox" class="form-check-input" name="lines[{{ $line->id }}][include]" value="1" @checked(old("lines.{$line->id}.include", $planned && ! $errors->any()))></td>
                    <td class="app-num">{{ $line->line_no }}</td>
                    <td>@include('supplier-orders._photo', ['product' => $line->product])</td>
                    <td style="white-space:normal">
                        <div class="fw-semibold">{{ $line->product?->name }}</div>
                        <div class="text-muted small">{{ $line->description }}</div>
                    </td>
                    <td>{{ $seeSuppliers ? ($line->supplier?->name ?? '—') : '—' }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->total_qty) }} {{ $line->unit }}</td>
                    <td>
                        <input type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][unit_price]" class="form-control form-control-sm" dir="ltr"
                               value="{{ old("lines.{$line->id}.unit_price", $line->supplier_price !== null ? Quantity::trim($line->supplier_price) : '') }}">
                        @if($line->supplier_currency && $line->supplier_currency !== $order->currency)
                            <div class="form-text text-warning">{{ __('Planned in :currency', ['currency' => $line->supplier_currency]) }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="p-3 d-flex gap-2">
        <button class="btn btn-primary">{{ __('Add lines') }}</button>
        <a href="{{ route('supplier-orders.show', $order) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endif
@endsection
