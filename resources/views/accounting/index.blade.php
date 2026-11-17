@extends('layouts.app')

@section('title', __('Chart of accounts'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Accounting') }}</h1>
    @can('accounting.edit')
        <a href="{{ route('accounting.accounts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New account') }}</a>
    @endcan
</div>

@include('accounting._nav', ['active' => 'chart'])

<p class="text-muted small">{{ __('The chart is a starting point for MASDAR\'s accountant (spec Q-A4). Headings group sub-accounts and are never posted to. Balances are in :book, shown positive when normal for the account\'s type.', ['book' => $book]) }}</p>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Code') }}</th><th>{{ __('Account') }}</th><th>{{ __('Type') }}</th><th>{{ __('Currency') }}</th>
                <th>{{ __('Takes postings') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Balance (:book)', ['book' => $book]) }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php $a = $row['account']; @endphp
                <tr class="{{ $a->is_postable ? '' : 'fw-semibold table-light' }}">
                    <td class="app-num">{{ $a->code }}</td>
                    <td><span style="padding-inline-start: {{ $row['depth'] * 1.5 }}rem">{{ $a->displayName() }}</span></td>
                    <td>{{ $a->typeLabel() }}</td>
                    <td>{{ $a->currency ?? __('Any') }}</td>
                    <td>@if($a->is_postable)<i class="bi bi-check-lg text-success" title="{{ __('Yes') }}"></i>@else<span class="text-muted small">{{ __('Heading') }}</span>@endif</td>
                    <td>@if($a->active)<span class="app-pill app-pill--done">{{ __('Active') }}</span>@else<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endif</td>
                    <td class="app-num text-end">{{ $fmt($row['amount']) }}</td>
                    <td class="text-end text-nowrap">
                        @if($a->is_postable)
                            @can('finance_reports.view')
                                <a href="{{ route('finance-reports.account-ledger', ['account_id' => $a->id]) }}" class="btn btn-sm btn-light" title="{{ __('Account ledger') }}"><i class="bi bi-list-ul"></i></a>
                            @endcan
                        @endif
                        @can('accounting.edit')
                            <a href="{{ route('accounting.accounts.edit', $a) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="app-empty">{{ __('No accounts yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
