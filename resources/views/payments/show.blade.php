@extends('layouts.app')

@section('title', $payment->number)

@php
    use App\Models\MoneyAccount;
    use App\Models\Payment;
    use App\Services\Accounting\Decimal;
    use App\Services\Finance\MoneyFormat;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\HtmlString;

    $partyRoute = match (true) {
        $payment->party?->is_client && $payment->kind === 'client_receipt' => 'clients.show',
        $payment->party?->is_supplier && $payment->kind === 'supplier_payment' => 'suppliers.show',
        $payment->party?->is_shipping && $payment->kind === 'shipping_payment' => 'shipping-companies.show',
        (bool) $payment->party?->is_client => 'clients.show',
        (bool) $payment->party?->is_shipping => 'shipping-companies.show',
        (bool) $payment->party?->is_supplier => 'suppliers.show',
        default => null,
    };
    $in = $payment->direction === 'in';
    $ltr = fn (?string $text) => new HtmlString('<span class="app-ltr">'.e($text).'</span>');
    $link = fn (?string $route, $param, string $text, bool $ltrText = true) => new HtmlString(
        $route && Route::has($route)
            ? '<a href="'.e(route($route, $param)).'"'.($ltrText ? ' class="app-ltr"' : '').'>'.e($text).'</a>'
            : ($ltrText ? '<span class="app-ltr">'.e($text).'</span>' : e($text))
    );

    $details = [
        __('Date') => new HtmlString('<span class="app-num">'.e($payment->paid_on->toDateString()).'</span>'),
        __('Money account') => new HtmlString('<a href="'.e(route('money-accounts.show', $payment->moneyAccount)).'">'.e($payment->moneyAccount->label()).'</a> <span class="text-muted">· '.e(MoneyAccount::locationLabel($payment->moneyAccount->location)).'</span>'),
    ];
    if ($payment->party) {
        $details[__('Party')] = new HtmlString($link($partyRoute, $payment->party, $payment->party->name, false).' <span class="text-muted small app-ltr">'.e($payment->party->code).'</span>');
    }
    if ($payment->proformaInvoice) {
        $details[__('Proforma invoice')] = $link('proforma-invoices.show', $payment->proformaInvoice, $payment->proformaInvoice->reference());
    }
    if ($payment->supplierOrder) {
        $details[__('Supplier order')] = $link('supplier-orders.show', $payment->supplierOrder, $payment->supplierOrder->number);
    }
    if ($payment->container) {
        $containerLabel = ($payment->container->container_no ?: '#'.$payment->container->id).($payment->container->shipment ? ' · '.$payment->container->shipment->number : '');
        $details[__('Container')] = $link('containers.show', $payment->container, $containerLabel);
    }
    if ($payment->counterAccount) {
        $details[__('Counter account')] = new HtmlString('<span class="app-ltr">'.e($payment->counterAccount->code).'</span> — '.e($payment->counterAccount->displayName()));
    }
    $details[__('Reference')] = $payment->reference ? $ltr($payment->reference) : null;
    $details[__('Recorded by')] = new HtmlString(e($payment->user?->name).' · <span class="app-num">'.e($payment->created_at?->format('Y-m-d H:i')).'</span>');

    // Paid on the PI against its total (the current revision, in the PI's currency).
    $piBar = null;
    if ($payment->proformaInvoice) {
        $paidOnPi = $queries->paidForPiNumber($payment->proformaInvoice->pi_number, $payment->proformaInvoice->currency);
        if ($piCurrent && $piCurrent->currency === $payment->proformaInvoice->currency && Decimal::isPositive((string) $piCurrent->total_amount)) {
            $total = (string) $piCurrent->total_amount;
            $piBar = [
                'paid' => $paidOnPi,
                'total' => $total,
                'tone' => Decimal::cmp($paidOnPi, $total) >= 0 ? 'done' : 'gold',
                'left' => Decimal::sub($total, $paidOnPi),
            ];
        }
    }
@endphp

@section('content')
<x-page-header :title="$ltr($payment->number)" :back="route('payments.index')" :back-label="__('All payments')"
               :subtitle="Payment::kindLabel($payment->kind).' · '.($in ? __('Money in') : __('Money out'))">
    <x-slot:meta>@include('payments._pills', ['payment' => $payment])</x-slot:meta>
</x-page-header>

@if($errors->has('payment'))
    <div class="alert alert-danger" role="alert">{{ $errors->first('payment') }}</div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <x-section :title="__('Payment')" :icon="$in ? 'box-arrow-in-down' : 'box-arrow-up'" class="h-100">
            <div class="app-amount-hero {{ $effective ? '' : 'app-amount-hero--pending' }}">
                <div class="app-amount-hero__label">
                    <span class="app-dir {{ $in ? 'app-dir--in' : 'app-dir--out' }}"><i class="bi {{ $in ? 'bi-arrow-down-left' : 'bi-arrow-up-right' }}" aria-hidden="true"></i></span>
                    {{ __('Amount') }}
                </div>
                <div class="app-amount-hero__value"><span class="app-num">{{ MoneyFormat::amount($payment->amount) }}</span><span class="app-unit">{{ $payment->currency }}</span></div>
                <div class="app-amount-hero__sub">
                    {{ __('Book amount') }}: <span class="app-num fw-semibold">{{ MoneyFormat::amount($payment->book_amount) }}</span> {{ $book }}
                    · {{ __('Rate to :book', ['book' => $book]) }} <span class="app-num">{{ MoneyFormat::rate($payment->rate) }}</span>
                </div>
                <div class="app-amount-hero__sub">
                    {{ __('Effect on the balance') }}: <span class="app-num fw-semibold">{{ MoneyFormat::amount($payment->signed_amount) }}</span> {{ $payment->currency }}
                    @unless($effective)<x-status tone="due" icon="hourglass-split" class="ms-1">{{ __('not counted yet') }}</x-status>@endunless
                </div>
            </div>

            <x-detail-list :items="$details" :cols="2" />

            @if($payment->note)
                <div class="mt-3">
                    <div class="small fw-semibold text-muted mb-1">{{ $payment->isReversal() ? __('Reason') : __('Note') }}</div>
                    <div style="white-space:pre-line">{{ $payment->note }}</div>
                </div>
            @endif

            @if($payment->proformaInvoice)
                <div class="mt-4 pt-3 border-top">
                    @if($piBar)
                        <x-progress :value="$piBar['paid']" :max="$piBar['total']" :tone="$piBar['tone']"
                                    :label="__('Paid on :pi', ['pi' => $payment->proformaInvoice->pi_number])"
                                    :caption="MoneyFormat::amount($piBar['paid']).' / '.MoneyFormat::amount($piBar['total']).' '.$payment->proformaInvoice->currency" />
                        <div class="small text-muted mt-1">
                            @if(Decimal::isPositive($piBar['left']))
                                {{ __('Still to pay: :amount :currency', ['amount' => MoneyFormat::amount($piBar['left']), 'currency' => $payment->proformaInvoice->currency]) }}
                            @else
                                <i class="bi bi-check-circle text-success" aria-hidden="true"></i> {{ __('Paid in full') }}
                            @endif
                        </div>
                    @else
                        <div class="small text-muted">{{ __('paid on this PI number: :amount :currency', [
                            'amount' => MoneyFormat::amount($queries->paidForPiNumber($payment->proformaInvoice->pi_number, $payment->proformaInvoice->currency)),
                            'currency' => $payment->proformaInvoice->currency,
                        ]) }}</div>
                    @endif
                </div>
            @endif
        </x-section>
    </div>

    <div class="col-lg-5">
        <div class="app-panel-stack">
            @if($payment->isReversal())
                <x-section :title="__('This is a reversal')" icon="arrow-counterclockwise">
                    <p class="mb-0">{{ __('It cancels') }} <a href="{{ route('payments.show', $payment->reverses) }}" class="app-ltr">{{ $payment->reverses->number }}</a>.
                        @if($payment->direction === 'out')
                            {{ __('Like any money out, it counts once a manager approves it.') }}
                        @endif
                    </p>
                </x-section>
            @endif

            @if($payment->direction === 'out')
                <x-section :title="__('Approval')" icon="patch-check">
                    @if($payment->approval)
                        <div class="d-flex gap-2 align-items-start">
                            <i class="bi bi-check-circle-fill text-success mt-1" aria-hidden="true"></i>
                            <div>
                                <div>{{ __('Approved by :name on :date.', ['name' => $payment->approval->approver?->name, 'date' => $payment->approval->approved_at?->format('Y-m-d H:i')]) }}</div>
                                @if($payment->approval->note)<div class="text-muted small mt-1">{{ $payment->approval->note }}</div>@endif
                            </div>
                        </div>
                    @elseif($payment->reversal)
                        <p class="text-muted mb-0">{{ __('Reversed before it was approved; it never counted.') }}</p>
                    @elseif($payment->isReversal() && ! $payment->reverses?->approval)
                        <p class="text-muted mb-0">{{ __('The payment this reverses was never approved, so neither of them counts and there is nothing to approve.') }}</p>
                    @elseif(! $approvalRequired)
                        <p class="text-muted mb-0">{{ __('Payments out do not need approval at the moment, so this one counts already.') }}</p>
                    @else
                        <div class="d-flex gap-2 align-items-start">
                            <i class="bi bi-hourglass-split text-warning mt-1" aria-hidden="true"></i>
                            <p class="mb-0">{{ __('Waiting for a manager\'s approval. Until then it does not count in the balances or the ledger.') }}</p>
                        </div>
                    @endif
                    @if($canApprove)
                        <form method="POST" action="{{ route('payments.approve', $payment) }}" class="mt-3 pt-3 border-top">
                            @csrf
                            <label class="form-label" for="approval-note">{{ __('Note (optional)') }}</label>
                            <input type="text" name="approval_note" id="approval-note" maxlength="1000" class="form-control mb-2">
                            <button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Approve') }}</button>
                        </form>
                    @endif
                </x-section>
            @endif

            @if($payment->transfer_group)
                <x-section :title="__('Transfer')" icon="arrow-left-right">
                    <ul class="app-legs">
                        @foreach($legs as $leg)
                            <li class="app-legs__row">
                                <span class="app-legs__main">
                                    <a href="{{ route('payments.show', $leg) }}" class="app-ltr fw-semibold">{{ $leg->number }}</a>
                                    <span class="app-legs__sub">{{ Payment::kindLabel($leg->kind) }} · {{ $leg->moneyAccount->name }}</span>
                                </span>
                                <span class="text-nowrap"><span class="app-num fw-semibold">{{ MoneyFormat::amount($leg->signed_amount) }}</span><span class="app-unit">{{ $leg->currency }}</span></span>
                                <span class="d-flex flex-wrap gap-1">@include('payments._pills', ['payment' => $leg])</span>
                            </li>
                        @endforeach
                    </ul>
                </x-section>
            @endif

            <x-section :title="__('Reversal')" icon="arrow-counterclockwise">
                @if($payment->reversal)
                    <p class="mb-0">{{ __('Reversed by') }} <a href="{{ route('payments.show', $payment->reversal) }}" class="app-ltr">{{ $payment->reversal->number }}</a> · <span class="app-num">{{ $payment->reversal->paid_on->toDateString() }}</span></p>
                    @if($payment->reversal->note)<div class="text-muted small mt-1" style="white-space:pre-line">{{ $payment->reversal->note }}</div>@endif
                @elseif($payment->isReversal())
                    <p class="text-muted mb-0">{{ __('A reversal is never reversed. If it was a mistake, record the payment again.') }}</p>
                @else
                    <p class="text-muted small mb-3">{{ __('A payment is never edited or deleted. To correct it, reverse it (a new row cancelling it) and record it again.') }}
                        @if($payment->transfer_group) {{ __('Both sides of the transfer are reversed together.') }} @endif</p>
                    @can('payments.edit')
                        <form method="POST" action="{{ route('payments.reverse', $payment) }}" onsubmit="return confirm(@js(__('Reverse this payment?')));">
                            @csrf
                            <label class="form-label" for="reverse-reason">{{ __('Why is it reversed?') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <textarea name="reason" id="reverse-reason" rows="2" maxlength="1000" class="form-control mb-2 @error('reason') is-invalid @enderror" required>{{ old('reason') }}</textarea>
                            @error('reason')<div class="app-field-error mb-2">{{ $message }}</div>@enderror
                            <button class="btn btn-outline-danger"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reverse') }}</button>
                        </form>
                    @endcan
                @endif
            </x-section>
        </div>
    </div>
</div>

@include('partials.attachments', ['attachable' => $payment, 'type' => 'payments'])
@endsection
