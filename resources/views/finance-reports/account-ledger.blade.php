@extends('layouts.app')

@section('title', __('Account ledger'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
@include('finance-reports._header', ['title' => __('Account ledger'), 'csv' => (bool) $report])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-12 col-md-5">
        <label class="form-label small" for="account_id">{{ __('Account') }}</label>
        <select id="account_id" name="account_id" class="form-select" required>
            <option value="">{{ __('— account —') }}</option>
            @foreach($accounts as $a)
                <option value="{{ $a->id }}" @selected($account?->id === $a->id)>{{ $a->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-6 col-md-2"><label class="form-label small" for="from">{{ __('From') }}</label><input id="from" type="date" name="from" value="{{ $from }}" class="form-control"></div>
    <div class="col-6 col-md-2"><label class="form-label small" for="to">{{ __('To') }}</label><input id="to" type="date" name="to" value="{{ $to }}" class="form-control"></div>
    <div class="col-12 col-md-3"><button class="btn btn-primary w-100">{{ __('Show') }}</button></div>
</form>

@if($report)
    <p class="text-muted small">{{ __('Balances in :book, positive when normal for a :type account.', ['book' => $book, 'type' => $account->typeLabel()]) }}</p>
    <div class="app-card">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr>
                    <th>{{ __('Date') }}</th><th>{{ __('Entry') }}</th><th>{{ __('Description') }}</th><th>{{ __('Party') }}</th>
                    <th class="text-end">{{ __('Amount in currency') }}</th><th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th><th class="text-end">{{ __('Balance') }}</th>
                </tr></thead>
                <tbody>
                    <tr class="table-light"><td colspan="7">{{ __('Opening balance') }}</td><td class="app-num text-end">{{ $fmt($report['opening']) }}</td></tr>
                    @forelse($report['rows'] as $r)
                        <tr>
                            <td class="app-num text-nowrap">{{ substr($r['entry_date'], 0, 10) }}</td>
                            <td class="app-num text-nowrap"><a href="{{ route('accounting.journal.show', $r['journal_entry_id']) }}">{{ $r['number'] }}</a></td>
                            <td>{{ $r['description'] }}@if($r['memo'])<div class="small text-muted">{{ $r['memo'] }}</div>@endif</td>
                            <td>{{ $r['party_name'] ?? '—' }}</td>
                            <td class="app-num text-end text-nowrap">{{ $fmt($r['amount_currency']) }} {{ $r['currency'] }}</td>
                            <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($r['debit']) ? '' : $fmt($r['debit']) }}</td>
                            <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($r['credit']) ? '' : $fmt($r['credit']) }}</td>
                            <td class="app-num text-end">{{ $fmt($r['running']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="app-empty">{{ __('Nothing posted in this range.') }}</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr class="fw-bold"><td colspan="5">{{ __('Closing balance') }}</td><td class="app-num text-end">{{ $fmt($report['debit']) }}</td><td class="app-num text-end">{{ $fmt($report['credit']) }}</td><td class="app-num text-end">{{ $fmt($report['closing']) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </div>
@else
    <div class="app-card p-4 text-muted">{{ __('Choose an account.') }}</div>
@endif
@endsection
