@extends('layouts.app')

@section('title', __('Products'))

@php
    $n = fn ($value) => $value === null ? '—' : rtrim(rtrim((string) $value, '0'), '.');
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Products') }}</h1>
    @can('products.edit')
        <a href="{{ route('products.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New product') }}</a>
    @endcan
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control" style="max-inline-size:300px" placeholder="{{ __('Search by code, name or HS code') }}">
    <select name="active" class="form-select" style="max-inline-size:160px" onchange="this.form.submit()">
        @foreach(['1' => __('Active'), '0' => __('Inactive'), 'all' => __('All')] as $value => $label)
            <option value="{{ $value }}" @selected($filters['active'] === (string) $value)>{{ $label }}</option>
        @endforeach
    </select>
    @if($canSeeSupplier)
        <select name="supplier" class="form-select" style="max-inline-size:240px" onchange="this.form.submit()">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected($filters['supplier'] === $s->id)>{{ $s->code }} · {{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <button class="btn btn-light">{{ __('Search') }}</button>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th></th><th>{{ __('Code') }}</th><th>{{ __('Name') }}</th><th>{{ __('Unit') }}</th>
                <th class="text-end">{{ __('Qty / carton') }}</th><th class="text-end">{{ __('CBM / carton') }}</th><th class="text-end">{{ __('KG / carton') }}</th>
                @if($canSeeSupplier)<th>{{ __('Supplier') }}</th><th class="text-end">{{ __('Supplier price') }}</th>@endif
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($products as $p)
                <tr>
                    <td>
                        @if($p->photo)
                            <img src="{{ route('products.photo', $p) }}" alt="" class="app-thumb" loading="lazy">
                        @else
                            <span class="app-thumb d-inline-flex align-items-center justify-content-center text-muted"><i class="bi bi-image fs-4"></i></span>
                        @endif
                    </td>
                    <td class="app-num">{{ $p->code }}</td>
                    <td>
                        <a href="{{ route('products.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->name }}</a>
                        @if($p->name_local)<div class="small text-muted">{{ $p->name_local }}</div>@endif
                        @unless($p->active)<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endunless
                    </td>
                    <td>{{ $units[$p->unit] ?? $p->unit }}</td>
                    <td class="app-num text-end">{{ $n($p->qty_per_carton) }}</td>
                    <td class="app-num text-end">{{ $n($p->cbm_per_carton) }}</td>
                    <td class="app-num text-end">{{ $n($p->kg_per_carton) }}</td>
                    @if($canSeeSupplier)
                        <td>{{ $p->supplier?->name ?? '—' }}</td>
                        <td class="app-num text-end">{{ $p->supplier_price !== null ? $n($p->supplier_price).' '.$p->supplier_currency : '—' }}</td>
                    @endif
                    <td class="text-end text-nowrap">
                        <a href="{{ route('products.show', $p) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        @can('products.edit')
                            <a href="{{ route('products.edit', $p) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="app-empty"><i class="bi bi-box-seam"></i>{{ __('No products found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($products->hasPages())<div class="p-3">{{ $products->links() }}</div>@endif
</div>
@endsection
