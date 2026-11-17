@extends('layouts.app')

@section('title', $account->name)

@php use App\Models\MoneyAccount; use App\Models\Payment; use App\Services\Finance\MoneyFormat; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ $account->name }} @unless($account->active)<span class="app-pill app-pill--skip">{{ __('Inactive') }}</span>@endunless</h1>
    <div class="d-flex gap-2">
        @can('payments.edit')
            <a href="{{ route('money-accounts.edit', $account) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
        @endcan
        <a href="{{ route('money-accounts.index') }}" class="btn btn-light">{{ __('All money accounts') }}</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="app-card app-stat">
        <div class="app-stat__label">{{ __('Balance') }} ({{ $account->currency }})</div>
        <div class="app-stat__value"><span class="app-num">{{ MoneyFormat::amount($balance) }}</span></div>
    </div></div>
    <div class="col-md-8"><div class="app-card p-3 h-100">
        <dl class="row mb-0 small">
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Kind') }}</dt><dd class="col-sm-9">{{ MoneyAccount::kindLabel($account->kind) }}</dd>
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Where') }}</dt><dd class="col-sm-9">{{ MoneyAccount::locationLabel($account->location) }}</dd>
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Ledger account') }}</dt>
            <dd class="col-sm-9">@if($account->ledgerAccount)<span class="app-ltr">{{ $account->ledgerAccount->code }}</span> {{ $account->ledgerAccount->displayName() }}@else<span class="text-muted">{{ __('Not set') }}</span>@endif</dd>
            @if($account->details)
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Details') }}</dt><dd class="col-sm-9" style="white-space:pre-line">{{ $account->details }}</dd>
            @endif
        </dl>
    </div></div>
</div>

@unless($complete)
    <div class="alert alert-secondary py-2 small">{{ __('Some payments of this account are not shown to you, so the running balance is hidden.') }}</div>
@endunless

<div class="app-card">
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Party') }}</th>
                <th class="text-end">{{ __('Amount') }}</th>
                @if($complete)<th class="text-end">{{ __('Running balance') }}</th>@endif
                <th>{{ __('Status') }}</th>
            </tr></thead>
            <tbody>
            @forelse($history as $payment)
                <tr>
                    <td><a href="{{ route('payments.show', $payment) }}" class="app-ltr fw-semibold">{{ $payment->number }}</a></td>
                    <td class="app-num">{{ $payment->paid_on->toDateString() }}</td>
                    <td>{{ Payment::kindLabel($payment->kind) }}</td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td class="text-end app-num">{{ MoneyFormat::amount($payment->signed_amount) }}</td>
                    @if($complete)<td class="text-end app-num">{{ MoneyFormat::amount($payment->running_total) }}</td>@endif
                    <td>@include('payments._status', ['status' => $queries->status($payment, $approvalRequired)])</td>
                </tr>
            @empty
                <tr><td colspan="7" class="app-empty">{{ __('No payments in this account yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($history->hasPages())<div class="p-3">{{ $history->links() }}</div>@endif
</div>
<p class="text-muted small mt-2">{{ __('The running balance counts only payments that count; a payment awaiting approval leaves it unchanged.') }}</p>
@endsection
