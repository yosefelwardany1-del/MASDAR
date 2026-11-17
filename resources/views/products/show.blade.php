@extends('layouts.app')

@section('title', $product->code.' · '.$product->name)

@php
    use Illuminate\Support\Facades\Gate;
    $n = fn ($value) => $value === null ? '—' : rtrim(rtrim((string) $value, '0'), '.');
    $money = fn ($amount, $currency) => $amount === null ? '—' : $n($amount).' '.($currencies[$currency] ?? $currency);
    $hasSize = $product->carton_length_cm !== null || $product->carton_width_cm !== null || $product->carton_height_cm !== null;
@endphp

@section('content')
<x-page-header :title="$product->name" :subtitle="$product->name_local" :back="route('products.index')" :back-label="__('Products')">
    <x-slot:meta>
        <span class="app-doc-code">{{ $product->code }}</span>
        @if($product->active)
            <span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Active') }}</span>
        @else
            <span class="app-pill app-pill--skip"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Inactive') }}</span>
        @endif
    </x-slot:meta>
    @can('products.edit')
        <x-slot:actions>
            <a href="{{ route('products.edit', $product) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
            @if($product->active)
                <form method="POST" action="{{ route('products.deactivate', $product) }}" onsubmit="return confirm(@js(__('Deactivate this product? It stays on every document that refers to it.')));">
                    @csrf<button class="btn btn-outline-danger"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Deactivate') }}</button>
                </form>
            @else
                <form method="POST" action="{{ route('products.reactivate', $product) }}">
                    @csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reactivate') }}</button>
                </form>
            @endif
        </x-slot:actions>
    @endcan
</x-page-header>

<div class="app-product-page">
    <figure class="app-card app-product-page__photo">
        @if($product->photo)
            <a href="{{ route('products.photo', $product) }}" target="_blank" rel="noopener" title="{{ __('Open the photo') }}">
                <img src="{{ route('products.photo', $product) }}" alt="{{ $product->name }}" width="480" height="480">
            </a>
        @else
            <x-empty icon="image" :title="__('No photo yet.')" :text="__('A photo shows on every PI line of this product.')">
                @can('products.edit')
                    <x-slot:action><a href="{{ route('products.edit', $product) }}" class="btn btn-light btn-sm"><i class="bi bi-upload" aria-hidden="true"></i>{{ __('Add photo') }}</a></x-slot:action>
                @endcan
            </x-empty>
        @endif
    </figure>

    <div class="app-product-page__info">
        @if($product->description)
            <x-section :title="__('Description')" icon="card-text">
                <div class="app-product-page__desc" dir="auto">{!! nl2br(e($product->description)) !!}</div>
            </x-section>
        @endif

        <x-section :title="__('Carton')" icon="box-seam">
            <dl class="app-product-page__packing">
                <div><dt>{{ __('Unit') }}</dt><dd>{{ $units[$product->unit] ?? $product->unit }}</dd></div>
                <div><dt>{{ __('Quantity per carton') }}</dt><dd class="app-num">{{ $n($product->qty_per_carton) }}</dd></div>
                <div><dt>{{ __('Carton size (cm)') }}</dt><dd>@if($hasSize)<span class="app-num">{{ $n($product->carton_length_cm) }} × {{ $n($product->carton_width_cm) }} × {{ $n($product->carton_height_cm) }}</span>@else — @endif</dd></div>
                <div><dt>{{ __('CBM per carton') }}</dt><dd class="app-num">{{ $n($product->cbm_per_carton) }}</dd></div>
                <div><dt>{{ __('Gross KG per carton') }}</dt><dd class="app-num">{{ $n($product->kg_per_carton) }}</dd></div>
                <div><dt>{{ __('HS code') }}</dt><dd class="app-num">{{ $product->hs_code ?: '—' }}</dd></div>
            </dl>
        </x-section>

        <x-section :title="__('Prices')" icon="tags">
            <dl class="app-product-page__packing">
                <div><dt>{{ __('Sale price') }}</dt><dd class="app-num app-product-page__price">{{ $money($product->sale_price, $product->sale_currency) }}</dd></div>
            </dl>
            @if($canSeeSupplier)
                <div class="app-doc-internal mt-3">
                    <div class="app-doc-internal__label"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Supplier') }} · {{ __('Never printed on the PI.') }}</div>
                    <dl class="app-product-page__packing">
                        <div><dt>{{ __('Usual supplier') }}</dt>
                            <dd>
                                @if($product->supplier)
                                    @if(Gate::allows('suppliers.view'))<a href="{{ route('suppliers.show', $product->supplier) }}">{{ $product->supplier->name }}</a>@else{{ $product->supplier->name }}@endif
                                    <span class="app-doc-code">{{ $product->supplier->code }}</span>
                                @else — @endif
                            </dd></div>
                        <div><dt>{{ __('Supplier price') }}</dt><dd class="app-num" data-test="supplier-price">{{ $money($product->supplier_price, $product->supplier_currency) }}</dd></div>
                    </dl>
                </div>
            @endif
        </x-section>
    </div>
</div>
@endsection
