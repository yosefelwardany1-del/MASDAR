@extends('layouts.app')

@section('title', $product->code.' · '.$product->name)

@php
    use Illuminate\Support\Facades\Gate;
    $n = fn ($value) => $value === null ? '—' : rtrim(rtrim((string) $value, '0'), '.');
    $money = fn ($amount, $currency) => $amount === null ? '—' : $n($amount).' '.($currencies[$currency] ?? $currency);
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><span class="app-num text-muted me-2">{{ $product->code }}</span>{{ $product->name }}</h1>
        @if($product->name_local)<div class="text-muted">{{ $product->name_local }}</div>@endif
        @if($product->active)<span class="app-pill app-pill--done">{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endif
    </div>
    @can('products.edit')
        <div class="d-flex gap-2">
            <a href="{{ route('products.edit', $product) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
            @if($product->active)
                <form method="POST" action="{{ route('products.deactivate', $product) }}" onsubmit="return confirm(@js(__('Deactivate this product? It stays on every document that refers to it.')));">
                    @csrf<button class="btn btn-light text-danger"><i class="bi bi-slash-circle me-1"></i>{{ __('Deactivate') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('products.reactivate', $product) }}">
                    @csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reactivate') }}</button>
                </form>
            @endif
        </div>
    @endcan
</div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="app-card p-3 text-center">
            @if($product->photo)
                <img src="{{ route('products.photo', $product) }}" alt="{{ $product->name }}" class="img-fluid rounded" style="max-block-size:320px">
            @else
                <div class="app-empty"><i class="bi bi-image"></i>{{ __('No photo yet.') }}</div>
            @endif
        </div>
    </div>
    <div class="col-md-8">
        <div class="app-card p-4">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Description') }}</dt><dd class="col-sm-8">{!! $product->description ? nl2br(e($product->description)) : '—' !!}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Unit') }}</dt><dd class="col-sm-8">{{ $units[$product->unit] ?? $product->unit }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Quantity per carton') }}</dt><dd class="col-sm-8 app-num">{{ $n($product->qty_per_carton) }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Carton size (cm)') }}</dt>
                <dd class="col-sm-8"><span class="app-num">{{ $n($product->carton_length_cm) }} × {{ $n($product->carton_width_cm) }} × {{ $n($product->carton_height_cm) }}</span></dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('CBM per carton') }}</dt><dd class="col-sm-8 app-num">{{ $n($product->cbm_per_carton) }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Gross KG per carton') }}</dt><dd class="col-sm-8 app-num">{{ $n($product->kg_per_carton) }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('HS code') }}</dt><dd class="col-sm-8 app-num">{{ $product->hs_code ?: '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Sale price') }}</dt><dd class="col-sm-8 app-num">{{ $money($product->sale_price, $product->sale_currency) }}</dd>
                @if($canSeeSupplier)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Usual supplier') }}</dt>
                    <dd class="col-sm-8">
                        @if($product->supplier)
                            @if(Gate::allows('suppliers.view'))<a href="{{ route('suppliers.show', $product->supplier) }}">{{ $product->supplier->name }}</a>@else{{ $product->supplier->name }}@endif
                            <span class="app-num text-muted">{{ $product->supplier->code }}</span>
                        @else — @endif
                    </dd>
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Supplier price') }}</dt><dd class="col-sm-8 app-num" data-test="supplier-price">{{ $money($product->supplier_price, $product->supplier_currency) }}</dd>
                @endif
            </dl>
        </div>
    </div>
</div>
@endsection
