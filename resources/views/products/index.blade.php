@extends('layouts.app')

@section('title', __('Products'))

@php
    use App\Services\ProformaInvoices\PiAmounts;

    $n = fn ($value) => $value === null ? '—' : rtrim(rtrim((string) $value, '0'), '.');
    $price = fn ($amount, $currency) => $amount === null ? '—' : PiAmounts::money((string) $amount, (string) $currency);
    // Photos first; the list keeps every figure in columns for scanning.
    $asList = request('view') === 'list';
    $filtered = $filters['search'] !== '' || $filters['active'] !== '1' || $filters['supplier'];
@endphp

@section('content')
<x-page-header :title="__('Products')" :subtitle="__('Found: :count', ['count' => $products->total()])">
    <x-slot:actions>
        <div class="app-viewswitch" role="group" aria-label="{{ __('Show as') }}">
            <a href="{{ request()->fullUrlWithQuery(['view' => null, 'page' => null]) }}" class="app-viewswitch__btn {{ $asList ? '' : 'is-active' }}" @unless($asList) aria-current="true" @endunless title="{{ __('Photos') }}"><i class="bi bi-grid-3x3-gap" aria-hidden="true"></i><span class="visually-hidden">{{ __('Photos') }}</span></a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'list', 'page' => null]) }}" class="app-viewswitch__btn {{ $asList ? 'is-active' : '' }}" @if($asList) aria-current="true" @endif title="{{ __('Table') }}"><i class="bi bi-list-ul" aria-hidden="true"></i><span class="visually-hidden">{{ __('Table') }}</span></a>
        </div>
        @can('products.edit')
            <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New product') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3" role="search">
    @if($asList)<input type="hidden" name="view" value="list">@endif
    <div class="app-doc-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label class="visually-hidden" for="product-search">{{ __('Search by code, name or HS code') }}</label>
        <input type="search" id="product-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('Search by code, name or HS code') }}">
    </div>
    <label class="visually-hidden" for="product-active">{{ __('Status') }}</label>
    <select id="product-active" name="active" class="form-select app-doc-filter app-doc-filter--sm" onchange="this.form.submit()">
        @foreach(['1' => __('Active'), '0' => __('Inactive'), 'all' => __('All')] as $value => $label)
            <option value="{{ $value }}" @selected($filters['active'] === (string) $value)>{{ $label }}</option>
        @endforeach
    </select>
    @if($canSeeSupplier)
        <label class="visually-hidden" for="product-supplier">{{ __('Supplier') }}</label>
        <select id="product-supplier" name="supplier" class="form-select app-doc-filter" onchange="this.form.submit()">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected($filters['supplier'] === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Search') }}</button>
    @if($filtered)
        <a href="{{ route('products.index', $asList ? ['view' => 'list'] : []) }}" class="btn btn-link"><i class="bi bi-x-lg" aria-hidden="true"></i>{{ __('Clear filters') }}</a>
    @endif
</form>

@if($products->isEmpty())
    <div class="app-card">
        <x-empty icon="box-seam" :title="__('No products found.')"
                 :text="$filtered ? __('Nothing matches these filters. Clear them to see every product.') : __('Products carry their photo, carton size and prices onto every PI line.')">
            <x-slot:action>
                @if($filtered)
                    <a href="{{ route('products.index') }}" class="btn btn-light btn-sm">{{ __('Clear filters') }}</a>
                @else
                    @can('products.edit')<a href="{{ route('products.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New product') }}</a>@endcan
                @endif
            </x-slot:action>
        </x-empty>
    </div>
@elseif(! $asList)
    <ul class="app-products" aria-label="{{ __('Products') }}">
        @foreach($products as $p)
            <li class="app-product {{ $p->active ? '' : 'is-inactive' }}">
                <a href="{{ route('products.show', $p) }}" class="app-product__photo" tabindex="-1" aria-hidden="true">
                    @if($p->photo)
                        <img src="{{ route('products.photo', $p) }}" alt="" loading="lazy" width="320" height="320">
                    @else
                        <span class="app-product__nophoto"><i class="bi bi-image"></i></span>
                    @endif
                </a>
                @unless($p->active)<span class="app-pill app-pill--skip app-product__flag"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Inactive') }}</span>@endunless
                <div class="app-product__body">
                    <div class="app-product__code"><span class="app-doc-code">{{ $p->code }}</span><span>{{ $units[$p->unit] ?? $p->unit }}</span></div>
                    <a href="{{ route('products.show', $p) }}" class="app-product__name"><bdi>{{ $p->name }}</bdi></a>
                    @if($p->name_local)<div class="app-product__local">{{ $p->name_local }}</div>@endif
                    <dl class="app-product__specs">
                        <div><dt>{{ __('Qty / carton') }}</dt><dd class="app-num">{{ $n($p->qty_per_carton) }}</dd></div>
                        <div><dt>{{ __('CBM / carton') }}</dt><dd class="app-num">{{ $n($p->cbm_per_carton) }}</dd></div>
                        <div><dt>{{ __('KG / carton') }}</dt><dd class="app-num">{{ $n($p->kg_per_carton) }}</dd></div>
                    </dl>
                    <div class="app-product__prices">
                        @if($p->sale_price !== null)<span class="app-product__sale"><span class="visually-hidden">{{ __('Sale price') }}:</span><span class="app-num">{{ $price($p->sale_price, $p->sale_currency) }}</span></span>@endif
                        @if($canSeeSupplier && ($p->supplier || $p->supplier_price !== null))
                            <span class="app-product__supplier" title="{{ __('Supplier price') }}"><i class="bi bi-lock" aria-hidden="true"></i>
                                <bdi>{{ $p->supplier?->name ?? '—' }}</bdi>@if($p->supplier_price !== null) · <span class="app-num">{{ $n($p->supplier_price) }} {{ $p->supplier_currency }}</span>@endif</span>
                        @endif
                    </div>
                </div>
                @can('products.edit')
                    <a href="{{ route('products.edit', $p) }}" class="btn btn-sm btn-light app-product__edit" title="{{ __('Edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">{{ __('Edit') }} {{ $p->name }}</span></a>
                @endcan
            </li>
        @endforeach
    </ul>
@else
    <div class="app-card">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th scope="col"><span class="visually-hidden">{{ __('Photo') }}</span></th><th scope="col">{{ __('Code') }}</th><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Unit') }}</th>
                    <th scope="col" class="text-end">{{ __('Qty / carton') }}</th><th scope="col" class="text-end">{{ __('CBM / carton') }}</th><th scope="col" class="text-end">{{ __('KG / carton') }}</th>
                    <th scope="col" class="text-end">{{ __('Sale price') }}</th>
                    @if($canSeeSupplier)<th scope="col">{{ __('Supplier') }}</th><th scope="col" class="text-end">{{ __('Supplier price') }}</th>@endif
                    <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($products as $p)
                    <tr @class(['app-doc-muted' => ! $p->active])>
                        <td>
                            @if($p->photo)
                                <img src="{{ route('products.photo', $p) }}" alt="" class="app-thumb" width="48" height="48" loading="lazy">
                            @else
                                <span class="app-thumb d-inline-flex align-items-center justify-content-center text-muted" aria-hidden="true"><i class="bi bi-image fs-4"></i></span>
                            @endif
                        </td>
                        <td><span class="app-doc-code">{{ $p->code }}</span></td>
                        <td>
                            <a href="{{ route('products.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->name }}</a>
                            @if($p->name_local)<div class="small text-muted">{{ $p->name_local }}</div>@endif
                            @unless($p->active)<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endunless
                        </td>
                        <td>{{ $units[$p->unit] ?? $p->unit }}</td>
                        <td class="app-num text-end">{{ $n($p->qty_per_carton) }}</td>
                        <td class="app-num text-end">{{ $n($p->cbm_per_carton) }}</td>
                        <td class="app-num text-end">{{ $n($p->kg_per_carton) }}</td>
                        <td class="app-num text-end text-nowrap">{{ $price($p->sale_price, $p->sale_currency) }}</td>
                        @if($canSeeSupplier)
                            <td>{{ $p->supplier?->name ?? '—' }}</td>
                            <td class="app-num text-end text-nowrap">{{ $p->supplier_price !== null ? $n($p->supplier_price).' '.$p->supplier_currency : '—' }}</td>
                        @endif
                        <td class="text-end text-nowrap">
                            @can('products.edit')
                                <a href="{{ route('products.edit', $p) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">{{ __('Edit') }} {{ $p->name }}</span></a>
                            @endcan
                            <a href="{{ route('products.show', $p) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-chevron-{{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'left' : 'right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ __('View') }} {{ $p->name }}</span></a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@if($products->hasPages())<div class="mt-3">{{ $products->links() }}</div>@endif
@endsection
