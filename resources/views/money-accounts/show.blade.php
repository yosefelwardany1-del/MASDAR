@extends('layouts.app')

@section('title', $account->name)

@php
    use App\Models\MoneyAccount;
    use App\Models\Payment;
    use App\Services\Accounting\Decimal;
    use App\Services\Finance\MoneyFormat;
    use Illuminate\Support\HtmlString;

    $negative = Decimal::cmp($balance, '0') < 0;
    $balanceFig = new HtmlString('<span class="app-num app-fig'.($negative ? ' app-fig--neg' : '').'">'.e(MoneyFormat::amount($balance)).'</span>');
    $details = [
        __('Kind') => MoneyAccount::kindLabel($account->kind),
        __('Where') => MoneyAccount::locationLabel($account->location),
        __('Ledger account') => $account->ledgerAccount
            ? new HtmlString('<span class="app-ltr text-muted">'.e($account->ledgerAccount->code).'</span> '.e($account->ledgerAccount->displayName()))
            : new HtmlString('<span class="text-muted">'.e(__('Not set')).'</span>'),
        __('Details') => $account->details ? new HtmlString('<span style="white-space:pre-line">'.e($account->details).'</span>') : null,
    ];
@endphp

@section('content')
<x-page-header :title="$account->name" :back="route('money-accounts.index')" :back-label="__('All money accounts')"
               :subtitle="MoneyAccount::kindLabel($account->kind).' · '.MoneyAccount::locationLabel($account->location).' · '.$account->currency">
    @unless($account->active)
        <x-slot:meta><x-status tone="skip">{{ __('Inactive') }}</x-status></x-slot:meta>
    @endunless
    @can('payments.edit')
        <x-slot:actions>
            <a href="{{ route('money-accounts.edit', $account) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <x-kpi :label="__('Balance').' ('.$account->currency.')'" icon="wallet2" :tone="$negative ? 'late' : 'done'"
               :value="$balanceFig" />
    </div>
    <div class="col-md-8">
        <x-section :title="__('Details')" icon="info-circle" class="h-100">
            <x-detail-list :cols="2" :items="$details" />
        </x-section>
    </div>
</div>

@unless($complete)
    <div class="alert alert-secondary small"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>{{ __('Some payments of this account are not shown to you, so the running balance is hidden.') }}</div>
@endunless

<x-section :title="__('Payments')" icon="list-ul" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Kind') }}</th><th scope="col">{{ __('Party') }}</th>
                <th scope="col" class="text-end">{{ __('Amount') }} <span class="app-unit">{{ $account->currency }}</span></th>
                @if($complete)<th scope="col" class="text-end">{{ __('Running balance') }}</th>@endif
                <th scope="col">{{ __('Status') }}</th>
            </tr></thead>
            <tbody>
            @forelse($history as $payment)
                @php $in = $payment->direction === 'in'; @endphp
                <tr>
                    <td class="text-nowrap"><a href="{{ route('payments.show', $payment) }}" class="app-ltr fw-semibold">{{ $payment->number }}</a></td>
                    <td class="app-ledger__date"><span class="app-num">{{ $payment->paid_on->toDateString() }}</span></td>
                    <td>
                        <span class="app-kind">
                            <span class="app-dir {{ $in ? 'app-dir--in' : 'app-dir--out' }}"><i class="bi {{ $in ? 'bi-arrow-down-left' : 'bi-arrow-up-right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ $in ? __('Money in') : __('Money out') }}</span></span>
                            {{ Payment::kindLabel($payment->kind) }}
                        </span>
                    </td>
                    <td>{{ $payment->party?->name ?? '—' }}</td>
                    <td class="text-end"><span class="app-num app-fig">{{ MoneyFormat::amount($payment->signed_amount) }}</span></td>
                    @if($complete)<td class="text-end app-ledger__running"><span class="app-num app-fig {{ Decimal::cmp((string) $payment->running_total, '0') < 0 ? 'app-fig--neg' : '' }}">{{ MoneyFormat::amount($payment->running_total) }}</span></td>@endif
                    <td><div class="d-flex flex-wrap gap-1">@include('payments._pills', ['payment' => $payment])</div></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-0"><x-empty icon="cash-coin" :title="__('No payments in this account yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($history->hasPages())<div class="p-3 border-top">{{ $history->links() }}</div>@endif
</x-section>
<p class="app-note mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('The running balance counts only payments that count; a payment awaiting approval leaves it unchanged.') }}</span></p>
@endsection
