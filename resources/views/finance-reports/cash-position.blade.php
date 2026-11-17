@extends('layouts.app')

@section('title', __('Cash position'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
@include('finance-reports._header', ['title' => __('Cash position')])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-3"><label class="form-label small" for="as_at">{{ __('As at') }}</label><input id="as_at" type="date" name="as_at" value="{{ $asAt }}" class="form-control"></div>
    <div class="col-6 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
</form>

<p class="text-muted small">{{ __('The balance from payments is the sum of the account\'s effective payments in its own currency; the ledger balance is its ledger account in :book, from posted entries. A difference means payments wait to be posted, or the ledger account is shared or adjusted by hand.', ['book' => $book]) }}</p>

<div class="row g-3 mb-3">
    @foreach($report['by_currency'] as $currency => $total)
        <div class="col-6 col-md-3"><div class="app-card p-3"><div class="text-muted small">{{ $currency }}</div><div class="app-stat app-num">{{ $fmt($total) }}</div></div></div>
    @endforeach
    <div class="col-6 col-md-3"><div class="app-card p-3"><div class="text-muted small">{{ __('Ledger total (:book)', ['book' => $book]) }}</div><div class="app-stat app-num">{{ $fmt($report['book_total']) }}</div></div></div>
</div>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Money account') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Balance from payments') }}</th>
                <th>{{ __('Ledger account') }}</th><th class="text-end">{{ __('Ledger balance (:book)', ['book' => $book]) }}</th>
            </tr></thead>
            <tbody>
            @forelse($report['rows'] as $r)
                <tr class="{{ $r['account']->active ? '' : 'text-muted' }}">
                    <td>{{ $r['account']->name }}</td>
                    <td>{{ $r['account']->currency }}</td>
                    <td class="app-num text-end">{{ $fmt($r['native']) }}</td>
                    <td>@if($r['account']->ledgerAccount){{ $r['account']->ledgerAccount->label() }}@else<span class="text-danger small">{{ __('Not set: set it on the Payments screen.') }}</span>@endif</td>
                    <td class="app-num text-end">{{ $r['book'] === null ? '—' : $fmt($r['book']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="app-empty">{{ __('No money accounts yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
