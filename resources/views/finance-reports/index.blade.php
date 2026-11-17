@extends('layouts.app')

@section('title', __('Financial reports'))

@section('content')
<h1 class="h4 mb-3">{{ __('Financial reports') }}</h1>
<p class="text-muted small">{{ __('Every figure is computed from posted journal entries, in the book currency. Drafts never count.') }}</p>
<div class="row g-3">
    @foreach([
        ['finance-reports.trial-balance', 'bi-list-columns', __('Trial balance'), __('Opening, debits, credits and closing balance of every account for a date range.')],
        ['finance-reports.profit-and-loss', 'bi-graph-up-arrow', __('Profit and loss'), __('Income and expenses for a period, grouped by heading, and the net result.')],
        ['finance-reports.balance-sheet', 'bi-bank', __('Balance sheet'), __('Assets, liabilities and equity as at a date, with the year\'s result.')],
        ['finance-reports.account-ledger', 'bi-journal-text', __('Account ledger'), __('One account\'s postings for a range, with a running balance.')],
        ['finance-reports.party-statement', 'bi-person-lines-fill', __('Party statement'), __('A client\'s, supplier\'s or shipping company\'s postings, with opening and running balance.')],
        ['finance-reports.cash-position', 'bi-wallet2', __('Cash position'), __('Each money account: its ledger balance and the balance of its payments, per currency.')],
    ] as [$route, $icon, $label, $text])
        <div class="col-md-6 col-lg-4">
            <a href="{{ route($route) }}" class="app-card p-3 d-block text-decoration-none h-100">
                <div class="fw-semibold mb-1"><i class="bi {{ $icon }} me-2"></i>{{ $label }}</div>
                <div class="text-muted small">{{ $text }}</div>
            </a>
        </div>
    @endforeach
</div>
@endsection
