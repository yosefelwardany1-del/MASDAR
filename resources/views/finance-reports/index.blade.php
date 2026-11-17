@extends('layouts.app')

@section('title', __('Financial reports'))

@section('content')
<x-page-header :title="__('Financial reports')" :subtitle="__('Every figure is computed from posted journal entries, in the book currency. Drafts never count.')" />

@foreach([
    __('Statements') => [
        ['finance-reports.profit-and-loss', 'graph-up-arrow', __('Profit and loss'), __('Income and expenses for a period, grouped by heading, and the net result.'), ''],
        ['finance-reports.balance-sheet', 'bank', __('Balance sheet'), __('Assets, liabilities and equity as at a date, with the year\'s result.'), ''],
        ['finance-reports.trial-balance', 'list-columns', __('Trial balance'), __('Opening, debits, credits and closing balance of every account for a date range.'), ''],
    ],
    __('Ledgers') => [
        ['finance-reports.account-ledger', 'journal-text', __('Account ledger'), __('One account\'s postings for a range, with a running balance.'), ''],
        ['finance-reports.party-statement', 'person-lines-fill', __('Party statement'), __('A client\'s, supplier\'s or shipping company\'s postings, with opening and running balance.'), ''],
    ],
    __('Cash') => [
        ['finance-reports.cash-position', 'wallet2', __('Cash position'), __('Each money account: its ledger balance and the balance of its payments, per currency.'), 'gold'],
    ],
] as $group => $reports)
    <section class="app-report-group" aria-labelledby="reports-{{ $loop->index }}">
        <h2 class="app-report-group__title" id="reports-{{ $loop->index }}">{{ $group }}</h2>
        <div class="app-reports">
            @foreach($reports as [$route, $icon, $label, $text, $tone])
                <a href="{{ route($route) }}" class="app-card app-report-tile {{ $tone ? 'app-report-tile--'.$tone : '' }}">
                    <span class="app-report-tile__icon"><i class="bi bi-{{ $icon }}" aria-hidden="true"></i></span>
                    <span>
                        <span class="app-report-tile__title">{{ $label }}</span>
                        <span class="app-report-tile__text d-block">{{ $text }}</span>
                    </span>
                    <i class="bi bi-arrow-right app-report-tile__go" aria-hidden="true"></i>
                </a>
            @endforeach
        </div>
    </section>
@endforeach
@endsection
