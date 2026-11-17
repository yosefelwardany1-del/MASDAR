@extends('layouts.app')

@section('title', __($kind->plural()))

@php
    use App\Services\Parties\PartyKind;
    $prefix = $kind->routePrefix();
    $isClient = $kind === PartyKind::Client;
    $isSupplier = $kind === PartyKind::Supplier;
    $isShipping = $kind === PartyKind::Shipping;
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __($kind->plural()) }}</h1>
    @can($kind->screen().'.edit')
        <a href="{{ route($prefix.'.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __($kind->newLabel()) }}</a>
    @endcan
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <input type="search" name="search" value="{{ $filters['search'] }}" class="form-control" style="max-inline-size:300px" placeholder="{{ __('Search by code, name or phone') }}">
    <select name="active" class="form-select" style="max-inline-size:160px" onchange="this.form.submit()">
        @foreach(['1' => __('Active'), '0' => __('Inactive'), 'all' => __('All')] as $value => $label)
            <option value="{{ $value }}" @selected($filters['active'] === (string) $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="country" class="form-select" style="max-inline-size:200px" onchange="this.form.submit()">
        <option value="">{{ __('All countries') }}</option>
        @foreach($countries as $code => $name)
            <option value="{{ $code }}" @selected($filters['country'] === $code)>{{ $name }}</option>
        @endforeach
    </select>
    @if($isClient)
        <select name="stage" class="form-select" style="max-inline-size:200px" onchange="this.form.submit()">
            <option value="">{{ __('All stages') }}</option>
            @foreach($stages as $code => $name)
                <option value="{{ $code }}" @selected($filters['stage'] === $code)>{{ $name }}</option>
            @endforeach
        </select>
        @if($managers->isNotEmpty())
            <select name="manager" class="form-select" style="max-inline-size:220px" onchange="this.form.submit()">
                <option value="">{{ __('All account managers') }}</option>
                @foreach($managers as $m)
                    <option value="{{ $m->id }}" @selected($filters['manager'] === $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
        @endif
    @endif
    <button class="btn btn-light">{{ __('Search') }}</button>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Code') }}</th>
                <th>{{ __('Name') }}</th>
                <th>{{ __('Country') }}</th>
                @if($isClient)<th>{{ __('Account manager') }}</th><th>{{ __('Stage') }}</th>@endif
                @if($isSupplier)<th>{{ __('What they make') }}</th>@endif
                @if($isShipping)<th>{{ __('Kind') }}</th>@endif
                <th>{{ __('Primary contact') }}</th>
                <th>{{ __('Status') }}</th>
                <th></th>
            </tr></thead>
            <tbody>
            @forelse($parties as $p)
                @php $contact = $p->primaryContact(); @endphp
                <tr>
                    <td class="app-num">{{ $p->code }}</td>
                    <td>
                        <a href="{{ route($prefix.'.show', $p) }}" class="fw-semibold text-decoration-none">{{ $p->name }}</a>
                        @if($p->name_local)<div class="text-muted small">{{ $p->name_local }}</div>@endif
                        @foreach($p->kinds() as $other)
                            @continue($other === $kind)
                            <span class="app-pill ms-1">{{ __($other->label()) }}</span>
                        @endforeach
                    </td>
                    <td>{{ $countries[$p->country] ?? $p->country ?? '—' }}@if($p->city) <span class="text-muted">· {{ $p->city }}</span>@endif</td>
                    @if($isClient)
                        <td>{{ $p->accountManager?->name ?? '—' }}</td>
                        <td>@if($p->stage)<span class="app-pill">{{ $stages[$p->stage] ?? $p->stage }}</span>@else — @endif</td>
                    @endif
                    @if($isSupplier)<td class="small">{{ \Illuminate\Support\Str::limit($p->products_note, 60) ?: '—' }}</td>@endif
                    @if($isShipping)<td>{{ $shippingKinds[$p->shipping_kind] ?? $p->shipping_kind ?? '—' }}</td>@endif
                    <td>
                        @if($contact)
                            {{ $contact->name }}
                            @if($contact->phone || $contact->whatsapp)<div class="small text-muted"><span class="app-num">{{ $contact->phone ?: $contact->whatsapp }}</span></div>@endif
                        @else — @endif
                    </td>
                    <td>@if($p->active)<span class="app-pill app-pill--done">{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endif</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route($prefix.'.show', $p) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        @can($kind->screen().'.edit')
                            <a href="{{ route($prefix.'.edit', $p) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="app-empty"><i class="bi {{ $kind->icon() }}"></i>{{ __('Nothing found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($parties->hasPages())<div class="p-3">{{ $parties->links() }}</div>@endif
</div>
@endsection
