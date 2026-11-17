@extends('layouts.app')

@section('title', __($kind->plural()))

@php
    use App\Services\Parties\PartyKind;
    $prefix = $kind->routePrefix();
    $isClient = $kind === PartyKind::Client;
    $isSupplier = $kind === PartyKind::Supplier;
    $isShipping = $kind === PartyKind::Shipping;
    $filtered = $filters['search'] !== '' || $filters['active'] !== '1' || $filters['country'] !== '' || $filters['stage'] !== '' || $filters['manager'];
    $initials = function (string $name) {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        return mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));
    };
@endphp

@section('content')
<x-page-header :title="__($kind->plural())" :subtitle="__('Found: :count', ['count' => $parties->total()])">
    @can($kind->screen().'.edit')
        <x-slot:actions>
            <a href="{{ route($prefix.'.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __($kind->newLabel()) }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3" role="search">
    <div class="app-doc-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label class="visually-hidden" for="party-search">{{ __('Search by code, name or phone') }}</label>
        <input type="search" id="party-search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('Search by code, name or phone') }}">
    </div>
    <label class="visually-hidden" for="party-active">{{ __('Status') }}</label>
    <select id="party-active" name="active" class="form-select app-doc-filter app-doc-filter--sm" onchange="this.form.submit()">
        @foreach(['1' => __('Active'), '0' => __('Inactive'), 'all' => __('All')] as $value => $label)
            <option value="{{ $value }}" @selected($filters['active'] === (string) $value)>{{ $label }}</option>
        @endforeach
    </select>
    <label class="visually-hidden" for="party-country">{{ __('Country') }}</label>
    <select id="party-country" name="country" class="form-select app-doc-filter" onchange="this.form.submit()">
        <option value="">{{ __('All countries') }}</option>
        @foreach($countries as $code => $name)
            <option value="{{ $code }}" @selected($filters['country'] === $code)>{{ $name }}</option>
        @endforeach
    </select>
    @if($isClient)
        <label class="visually-hidden" for="party-stage">{{ __('Stage') }}</label>
        <select id="party-stage" name="stage" class="form-select app-doc-filter" onchange="this.form.submit()">
            <option value="">{{ __('All stages') }}</option>
            @foreach($stages as $code => $name)
                <option value="{{ $code }}" @selected($filters['stage'] === $code)>{{ $name }}</option>
            @endforeach
        </select>
        @if($managers->isNotEmpty())
            <label class="visually-hidden" for="party-manager">{{ __('Account manager') }}</label>
            <select id="party-manager" name="manager" class="form-select app-doc-filter" onchange="this.form.submit()">
                <option value="">{{ __('All account managers') }}</option>
                @foreach($managers as $m)
                    <option value="{{ $m->id }}" @selected($filters['manager'] === $m->id)>{{ $m->name }}</option>
                @endforeach
            </select>
        @endif
    @endif
    <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Search') }}</button>
    @if($filtered)
        <a href="{{ route($prefix.'.index') }}" class="btn btn-link"><i class="bi bi-x-lg" aria-hidden="true"></i>{{ __('Clear filters') }}</a>
    @endif
</form>

<div class="app-card">
    @if($parties->isEmpty())
        <x-empty :icon="\Illuminate\Support\Str::after($kind->icon(), 'bi-')" :title="__('Nothing found.')"
                 :text="$filtered ? __('Nothing matches these filters. Clear them to see everyone.') : null">
            <x-slot:action>
                @if($filtered)
                    <a href="{{ route($prefix.'.index') }}" class="btn btn-light btn-sm">{{ __('Clear filters') }}</a>
                @else
                    @can($kind->screen().'.edit')<a href="{{ route($prefix.'.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __($kind->newLabel()) }}</a>@endcan
                @endif
            </x-slot:action>
        </x-empty>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-party-list">
                <thead><tr>
                    <th scope="col">{{ __('Name') }}</th>
                    <th scope="col">{{ __('Country') }}</th>
                    @if($isClient)<th scope="col">{{ __('Account manager') }}</th><th scope="col">{{ __('Stage') }}</th>@endif
                    @if($isSupplier)<th scope="col">{{ __('What they make') }}</th>@endif
                    @if($isShipping)<th scope="col">{{ __('Kind') }}</th>@endif
                    <th scope="col">{{ __('Primary contact') }}</th>
                    <th scope="col">{{ __('Status') }}</th>
                    <th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($parties as $p)
                    @php $contact = $p->primaryContact(); @endphp
                    <tr @class(['app-doc-muted' => ! $p->active])>
                        <td>
                            <div class="app-party-list__who">
                                <span class="app-party-list__mark" aria-hidden="true">{{ $initials($p->name) }}</span>
                                <div class="min-w-0">
                                    <a href="{{ route($prefix.'.show', $p) }}" class="app-party-list__name"><bdi>{{ $p->name }}</bdi></a>
                                    <div class="app-party-list__sub">
                                        <span class="app-doc-code">{{ $p->code }}</span>
                                        @if($p->name_local)<bdi>{{ $p->name_local }}</bdi>@endif
                                        @foreach($p->kinds() as $other)
                                            @continue($other === $kind)
                                            <span class="app-pill app-pill--info">{{ __($other->label()) }}</span>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td>{{ $countries[$p->country] ?? $p->country ?? '—' }}@if($p->city)<div class="app-party-list__sub">{{ $p->city }}</div>@endif</td>
                        @if($isClient)
                            <td><bdi>{{ $p->accountManager?->name ?? '—' }}</bdi></td>
                            <td>@if($p->stage)<span class="app-pill"><i class="bi bi-signpost" aria-hidden="true"></i>{{ $stages[$p->stage] ?? $p->stage }}</span>@else — @endif</td>
                        @endif
                        @if($isSupplier)<td class="app-party-list__note" dir="auto">{{ \Illuminate\Support\Str::limit($p->products_note, 60) ?: '—' }}</td>@endif
                        @if($isShipping)<td>{{ $shippingKinds[$p->shipping_kind] ?? $p->shipping_kind ?? '—' }}</td>@endif
                        <td>
                            @if($contact)
                                <bdi>{{ $contact->name }}</bdi>
                                @if($contact->phone || $contact->whatsapp)<div class="app-party-list__sub"><span class="app-num">{{ $contact->phone ?: $contact->whatsapp }}</span></div>@endif
                            @else — @endif
                        </td>
                        <td>@if($p->active)<span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Inactive') }}</span>@endif</td>
                        <td class="text-end text-nowrap">
                            @can($kind->screen().'.edit')
                                <a href="{{ route($prefix.'.edit', $p) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">{{ __('Edit') }} {{ $p->name }}</span></a>
                            @endcan
                            <a href="{{ route($prefix.'.show', $p) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-chevron-{{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'left' : 'right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ __('View') }} {{ $p->name }}</span></a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($parties->hasPages())<div class="p-3 border-top">{{ $parties->links() }}</div>@endif
</div>
@endsection
