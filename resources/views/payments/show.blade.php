@extends('layouts.app')

@section('title', $payment->number)

@php
    use App\Models\MoneyAccount;
    use App\Models\Payment;
    use App\Services\Finance\MoneyFormat;
    use Illuminate\Support\Facades\Route;

    $partyRoute = match (true) {
        $payment->party?->is_client && $payment->kind === 'client_receipt' => 'clients.show',
        $payment->party?->is_supplier && $payment->kind === 'supplier_payment' => 'suppliers.show',
        $payment->party?->is_shipping && $payment->kind === 'shipping_payment' => 'shipping-companies.show',
        (bool) $payment->party?->is_client => 'clients.show',
        (bool) $payment->party?->is_shipping => 'shipping-companies.show',
        (bool) $payment->party?->is_supplier => 'suppliers.show',
        default => null,
    };
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <h1 class="h4 mb-0"><span class="app-ltr">{{ $payment->number }}</span></h1>
        @include('payments._status', ['status' => $status])
    </div>
    <a href="{{ route('payments.index') }}" class="btn btn-light"><i class="bi bi-list me-1"></i>{{ __('All payments') }}</a>
</div>

@if($errors->has('payment'))
    <div class="alert alert-danger">{{ $errors->first('payment') }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="app-card p-4 h-100">
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Kind') }}</dt>
                <dd class="col-sm-8">{{ Payment::kindLabel($payment->kind) }} · {{ $payment->direction === 'in' ? __('Money in') : __('Money out') }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Date') }}</dt>
                <dd class="col-sm-8 app-num">{{ $payment->paid_on->toDateString() }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Money account') }}</dt>
                <dd class="col-sm-8"><a href="{{ route('money-accounts.show', $payment->moneyAccount) }}">{{ $payment->moneyAccount->label() }}</a> — {{ MoneyAccount::locationLabel($payment->moneyAccount->location) }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Amount') }}</dt>
                <dd class="col-sm-8"><span class="app-num fw-semibold">{{ MoneyFormat::amount($payment->amount) }}</span> {{ $payment->currency }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Rate to :book', ['book' => $book]) }}</dt>
                <dd class="col-sm-8 app-num">{{ MoneyFormat::rate($payment->rate) }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Book amount') }}</dt>
                <dd class="col-sm-8"><span class="app-num">{{ MoneyFormat::amount($payment->book_amount) }}</span> {{ $book }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Effect on the balance') }}</dt>
                <dd class="col-sm-8"><span class="app-num">{{ MoneyFormat::amount($payment->signed_amount) }}</span> {{ $payment->currency }}
                    @unless($effective)<span class="text-muted small">— {{ __('not counted yet') }}</span>@endunless
                </dd>
                @if($payment->party)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Party') }}</dt>
                    <dd class="col-sm-8">
                        @if($partyRoute && Route::has($partyRoute))<a href="{{ route($partyRoute, $payment->party) }}">{{ $payment->party->name }}</a>@else{{ $payment->party->name }}@endif
                        <span class="text-muted small app-ltr">{{ $payment->party->code }}</span>
                    </dd>
                @endif
                @if($payment->proformaInvoice)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Proforma invoice') }}</dt>
                    <dd class="col-sm-8">
                        @if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $payment->proformaInvoice) }}" class="app-ltr">{{ $payment->proformaInvoice->reference() }}</a>@else<span class="app-ltr">{{ $payment->proformaInvoice->reference() }}</span>@endif
                        <span class="text-muted small">· {{ __('paid on this PI number: :amount :currency', [
                            'amount' => MoneyFormat::amount($queries->paidForPiNumber($payment->proformaInvoice->pi_number, $payment->proformaInvoice->currency)),
                            'currency' => $payment->proformaInvoice->currency,
                        ]) }}</span>
                    </dd>
                @endif
                @if($payment->supplierOrder)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Supplier order') }}</dt>
                    <dd class="col-sm-8">
                        @if(Route::has('supplier-orders.show'))<a href="{{ route('supplier-orders.show', $payment->supplierOrder) }}" class="app-ltr">{{ $payment->supplierOrder->number }}</a>@else<span class="app-ltr">{{ $payment->supplierOrder->number }}</span>@endif
                    </dd>
                @endif
                @if($payment->container)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Container') }}</dt>
                    <dd class="col-sm-8">
                        @php $containerLabel = ($payment->container->container_no ?: '#'.$payment->container->id).($payment->container->shipment ? ' · '.$payment->container->shipment->number : ''); @endphp
                        @if(Route::has('containers.show'))<a href="{{ route('containers.show', $payment->container) }}" class="app-ltr">{{ $containerLabel }}</a>@else<span class="app-ltr">{{ $containerLabel }}</span>@endif
                    </dd>
                @endif
                @if($payment->counterAccount)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Counter account') }}</dt>
                    <dd class="col-sm-8"><span class="app-ltr">{{ $payment->counterAccount->code }}</span> — {{ $payment->counterAccount->displayName() }}</dd>
                @endif
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Reference') }}</dt>
                <dd class="col-sm-8"><span class="app-ltr">{{ $payment->reference ?: '—' }}</span></dd>
                @if($payment->note)
                    <dt class="col-sm-4 text-muted fw-normal">{{ $payment->isReversal() ? __('Reason') : __('Note') }}</dt>
                    <dd class="col-sm-8" style="white-space:pre-line">{{ $payment->note }}</dd>
                @endif
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Recorded by') }}</dt>
                <dd class="col-sm-8">{{ $payment->user?->name }} · <span class="app-num">{{ $payment->created_at?->format('Y-m-d H:i') }}</span></dd>
            </dl>
        </div>
    </div>

    <div class="col-lg-5 d-flex flex-column gap-3">
        @if($payment->isReversal())
            <div class="app-card p-3">
                <div class="fw-semibold mb-1"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('This is a reversal') }}</div>
                <div>{{ __('It cancels') }} <a href="{{ route('payments.show', $payment->reverses) }}" class="app-ltr">{{ $payment->reverses->number }}</a>.
                    {{ __('It counts exactly when the payment it reverses counts, so the two always net to zero.') }}</div>
            </div>
        @endif

        @if($payment->direction === 'out' && ! $payment->isReversal())
            <div class="app-card p-3">
                <div class="fw-semibold mb-2"><i class="bi bi-patch-check me-1"></i>{{ __('Approval') }}</div>
                @if($payment->approval)
                    <div>{{ __('Approved by :name on :date.', ['name' => $payment->approval->approver?->name, 'date' => $payment->approval->approved_at?->format('Y-m-d H:i')]) }}</div>
                    @if($payment->approval->note)<div class="text-muted small mt-1">{{ $payment->approval->note }}</div>@endif
                @elseif($payment->reversal)
                    <div class="text-muted">{{ __('Reversed before it was approved; it never counted.') }}</div>
                @elseif(! $approvalRequired)
                    <div class="text-muted">{{ __('Payments out do not need approval at the moment, so this one counts already.') }}</div>
                @else
                    <div class="mb-2">{{ __('Waiting for a manager\'s approval. Until then it does not count in the balances or the ledger.') }}</div>
                @endif
                @if($canApprove)
                    <form method="POST" action="{{ route('payments.approve', $payment) }}" class="mt-2">
                        @csrf
                        <input type="text" name="approval_note" maxlength="1000" class="form-control form-control-sm mb-2" placeholder="{{ __('Note (optional)') }}">
                        <button class="btn btn-sm btn-primary"><i class="bi bi-check2-circle me-1"></i>{{ __('Approve') }}</button>
                    </form>
                @endif
            </div>
        @endif

        @if($payment->transfer_group)
            <div class="app-card p-3">
                <div class="fw-semibold mb-2"><i class="bi bi-arrow-left-right me-1"></i>{{ __('Transfer') }}</div>
                <ul class="list-unstyled mb-0">
                    @foreach($legs as $leg)
                        <li class="d-flex flex-wrap justify-content-between gap-2 py-1">
                            <span><a href="{{ route('payments.show', $leg) }}" class="app-ltr">{{ $leg->number }}</a> · {{ Payment::kindLabel($leg->kind) }} · {{ $leg->moneyAccount->name }}</span>
                            <span><span class="app-num">{{ MoneyFormat::amount($leg->signed_amount) }}</span> {{ $leg->currency }} @include('payments._status', ['status' => $queries->status($leg, $approvalRequired)])</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="app-card p-3">
            <div class="fw-semibold mb-2"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reversal') }}</div>
            @if($payment->reversal)
                <div>{{ __('Reversed by') }} <a href="{{ route('payments.show', $payment->reversal) }}" class="app-ltr">{{ $payment->reversal->number }}</a> · <span class="app-num">{{ $payment->reversal->paid_on->toDateString() }}</span></div>
                @if($payment->reversal->note)<div class="text-muted small mt-1" style="white-space:pre-line">{{ $payment->reversal->note }}</div>@endif
            @elseif($payment->isReversal())
                <div class="text-muted">{{ __('A reversal is never reversed. If it was a mistake, record the payment again.') }}</div>
            @else
                <div class="text-muted small mb-2">{{ __('A payment is never edited or deleted. To correct it, reverse it (a new row cancelling it) and record it again.') }}
                    @if($payment->transfer_group) {{ __('Both sides of the transfer are reversed together.') }} @endif</div>
                @can('payments.edit')
                    <form method="POST" action="{{ route('payments.reverse', $payment) }}" onsubmit="return confirm(@js(__('Reverse this payment?')));">
                        @csrf
                        <textarea name="reason" rows="2" maxlength="1000" class="form-control form-control-sm mb-2 @error('reason') is-invalid @enderror" placeholder="{{ __('Why is it reversed?') }}" required>{{ old('reason') }}</textarea>
                        @error('reason')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reverse') }}</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
</div>

@include('partials.attachments', ['attachable' => $payment, 'type' => 'payments'])
@endsection
