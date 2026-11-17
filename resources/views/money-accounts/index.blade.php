@extends('layouts.app')

@section('title', __('Money accounts'))

@php use App\Models\MoneyAccount; use App\Services\Finance\MoneyFormat; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Money accounts') }}</h1>
    @can('payments.edit')
        <a href="{{ route('money-accounts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New money account') }}</a>
    @endcan
</div>

@include('payments._nav', ['active' => 'accounts'])

<h2 class="h6 text-muted mb-2">{{ __('Cash position') }}</h2>
<div class="row g-3 mb-4">
    @forelse($position as $currency => $amount)
        <div class="col-6 col-md-3"><div class="app-card app-stat">
            <div class="app-stat__label">{{ $currency }}</div>
            <div class="app-stat__value"><span class="app-num">{{ MoneyFormat::amount($amount) }}</span></div>
        </div></div>
    @empty
        <div class="col-12"><div class="app-card app-empty"><i class="bi bi-bank"></i>{{ __('No money accounts yet.') }}</div></div>
    @endforelse
</div>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Name') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Where') }}</th><th>{{ __('Ledger account') }}</th>
                <th class="text-end">{{ __('Balance') }}</th><th>{{ __('Status') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($accounts as $a)
                <tr>
                    <td><a href="{{ route('money-accounts.show', $a) }}" class="fw-semibold">{{ $a->name }}</a></td>
                    <td>{{ MoneyAccount::kindLabel($a->kind) }}</td>
                    <td>{{ MoneyAccount::locationLabel($a->location) }}</td>
                    <td>@if($a->ledgerAccount)<span class="app-ltr">{{ $a->ledgerAccount->code }}</span> {{ $a->ledgerAccount->displayName() }}@else<span class="text-muted">{{ __('Not set') }}</span>@endif</td>
                    <td class="text-end"><span class="app-num">{{ MoneyFormat::amount($balances[$a->id] ?? '0') }}</span> <small class="text-muted">{{ $a->currency }}</small></td>
                    <td>@if($a->active)<span class="app-pill app-pill--done">{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endif</td>
                    <td class="text-end">
                        <a href="{{ route('money-accounts.show', $a) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        @can('payments.edit')
                            <a href="{{ route('money-accounts.edit', $a) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="app-empty">{{ __('No money accounts yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2">{{ __('A balance is the sum of the account\'s payments that count: money in, and money out once approved (when approval is required).') }}</p>
@endsection
