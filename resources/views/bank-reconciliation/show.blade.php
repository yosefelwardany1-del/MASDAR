@extends('layouts.app')

@php
    use App\Services\Accounting\Decimal;
    $acc = $statement->moneyAccount;
    $canEdit = Gate::allows('bank_reconciliation.edit');
    $matched = $statement->lines->filter(fn ($l) => $l->match)->count();
    $closing = $statement->closing_amount !== null ? (string) $statement->closing_amount : null;
    // Left to right inside an Arabic line, so the period reads from → to.
    $period = "\u{2066}".$statement->period_from->format('Y-m-d').' → '.$statement->period_to->format('Y-m-d')."\u{2069} · ".$acc->currency;
    $difference = $closing !== null ? Decimal::sub($closing, $systemBalance) : null;
    $fig = fn ($v) => Decimal::format((string) $v);
@endphp

@section('title', __('Statement of :account', ['account' => $acc->name]))

@section('content')
<x-page-header :title="__('Statement of :account', ['account' => $acc->name])" :back="route('bank-reconciliation.index')" :back-label="__('Bank reconciliation')"
               :subtitle="$period">
    @if($canEdit)
        <x-slot:actions>
            <form method="POST" action="{{ route('bank-reconciliation.match-suggested', $statement) }}">@csrf<button class="btn btn-primary"><i class="bi bi-magic" aria-hidden="true"></i>{{ __('Match the sure ones') }}</button></form>
            @if($matched === 0)
                <form method="POST" action="{{ route('bank-reconciliation.destroy', $statement) }}" onsubmit="return confirm(@js(__('Delete this statement?')));">@csrf @method('DELETE')<button class="btn btn-light"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete') }}</button></form>
            @endif
        </x-slot:actions>
    @endif
</x-page-header>
@error('statement')<div class="alert alert-danger">{{ $message }}</div>@enderror
@error('payment_id')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Lines matched')" :value="$matched.' / '.$statement->lines->count()" icon="check2-square" :tone="$matched === $statement->lines->count() ? 'done' : 'due'" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Bank says (:currency)', ['currency' => $acc->currency])" :value="$closing !== null ? $fig($closing) : '—'" icon="bank2" :hint="__('Closing balance of the statement')" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('System says (:currency)', ['currency' => $acc->currency])" :value="$fig($systemBalance)" icon="wallet2" :hint="__('Payments that count, to :date', ['date' => $statement->period_to->format('Y-m-d')])" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Difference')" :value="$difference !== null ? $fig($difference) : '—'" icon="plus-slash-minus" :tone="$difference === null ? null : (Decimal::isZero($difference) ? 'done' : 'late')" /></div>
</div>

<x-section :title="__('Statement lines')" icon="list-ul" flush class="mb-4">
    <div class="table-responsive">
        <table class="table align-middle mb-0 app-ledger">
            <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Payment') }}</th></tr></thead>
            <tbody>
            @foreach($statement->lines as $line)
                @php $candidates = $suggestions->get($line->id, collect()); $neg = Decimal::cmp((string) $line->amount, '0') < 0; @endphp
                <tr>
                    <td class="text-nowrap"><span class="app-num">{{ $line->booked_on->format('Y-m-d') }}</span></td>
                    <td><span dir="auto">{{ $line->description }}</span>@if($line->reference)<div class="small text-muted app-ltr">{{ $line->reference }}</div>@endif</td>
                    <td class="app-figcell"><span class="app-num app-fig {{ $neg ? 'app-fig--neg' : 'app-fig--in' }}">{{ $fig($line->amount) }}</span></td>
                    <td style="min-inline-size: 16rem">
                        @if($line->match)
                            <x-status tone="done" icon="link-45deg"><a href="{{ route('payments.show', $line->match->payment) }}" class="app-ltr">{{ $line->match->payment->number }}</a></x-status>
                            @if($canEdit)<form method="POST" action="{{ route('bank-reconciliation.unmatch', [$statement, $line]) }}" class="d-inline">@csrf<button class="btn btn-sm btn-link">{{ __('Unmatch') }}</button></form>@endif
                        @elseif($canEdit)
                            <form method="POST" action="{{ route('bank-reconciliation.match', [$statement, $line]) }}" class="d-flex gap-1">
                                @csrf
                                <select name="payment_id" class="form-select form-select-sm" aria-label="{{ __('Payment') }}" required>
                                    <option value="">{{ $candidates->isEmpty() ? __('No payment of this amount nearby') : __('Choose…') }}</option>
                                    @foreach($candidates as $p)<option value="{{ $p->id }}" @selected($loop->first && $candidates->count() === 1)>{{ $p->number }} · {{ $p->paid_on->format('Y-m-d') }}</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-light" @disabled($candidates->isEmpty())>{{ __('Match') }}</button>
                            </form>
                            @if($candidates->isEmpty() && Gate::allows('payments.edit'))
                                <a class="small" href="{{ route('payments.create', ['kind' => $neg ? 'expense' : 'other_in', 'account' => $acc->id, 'date' => $line->booked_on->toDateString(), 'amount' => Decimal::abs((string) $line->amount), 'reference' => \Illuminate\Support\Str::limit((string) ($line->reference ?: $line->description), 110, '')]) }}"><i class="bi bi-plus-circle" aria-hidden="true"></i> {{ __('Record it as a payment') }}</a>
                            @endif
                        @else
                            <x-status tone="due">{{ __('Not matched') }}</x-status>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-section>

<x-section :title="__('In the system, not on the statement')" icon="exclamation-diamond" flush :subtitle="__('Payments of this account in the statement\'s dates that no line matches: not through the bank yet, or recorded wrongly.')">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Payment') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Reference') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th></tr></thead>
            <tbody>
            @forelse($unmatchedPayments as $p)
                <tr>
                    <td><a href="{{ route('payments.show', $p) }}" class="app-ltr">{{ $p->number }}</a> <span class="small text-muted">{{ \App\Models\Payment::kindLabel($p->kind) }}</span></td>
                    <td><span class="app-num">{{ $p->paid_on->format('Y-m-d') }}</span></td>
                    <td><span class="app-ltr small">{{ $p->reference }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig {{ Decimal::cmp((string) $p->signed_amount, '0') < 0 ? 'app-fig--neg' : '' }}">{{ $fig($p->signed_amount) }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="p-0"><div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Every payment in these dates is on the statement.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
