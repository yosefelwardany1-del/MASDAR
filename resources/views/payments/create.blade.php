@extends('layouts.app')

@section('title', __('Record a payment'))

@php use App\Models\Payment; @endphp

@section('content')
<x-page-header :title="__('Record a payment')" :back="route('payments.index')" :back-label="__('Payments')"
               :subtitle="__('One receipt or payment through one of MASDAR\'s accounts. Choose its kind first.')">
    @if($canTransfer)
        <x-slot:actions>
            <a href="{{ route('payments.transfer.create') }}" class="btn btn-light"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>{{ __('Transfer between accounts') }}</a>
        </x-slot:actions>
    @endif
</x-page-header>

<nav aria-label="{{ __('Kind') }}">
    <ul class="nav nav-tabs app-subnav">
        @foreach($kinds as $k)
            <li class="nav-item">
                <a class="nav-link {{ $k === $kind ? 'active' : '' }}" href="{{ route('payments.create', ['kind' => $k]) }}" @if($k === $kind) aria-current="page" @endif>
                    <i class="bi {{ in_array($k, Payment::KINDS_IN, true) ? 'bi-box-arrow-in-down' : 'bi-box-arrow-up' }}" aria-hidden="true"></i>{{ Payment::kindLabel($k) }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>

<form method="POST" action="{{ route('payments.store') }}" enctype="multipart/form-data" class="app-form">
    @csrf
    <input type="hidden" name="kind" value="{{ $kind }}">

    @include('configuration._form-errors')
    @error('kind')<div class="alert alert-danger mb-0" role="alert">{{ $message }}</div>@enderror
    @if(in_array($kind, Payment::KINDS_OUT, true) && $approvalRequired)
        <div class="alert alert-info mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('Money out waits for a manager\'s approval before it counts in the balances and the ledger.') }}</div>
    @endif
    <p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

    <x-section :title="__('Amount and account')" icon="cash-coin">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label" for="pay-date">{{ __('Date') }}<span class="app-req" aria-hidden="true">*</span></label>
                <input type="date" name="paid_on" id="pay-date" class="form-control @error('paid_on') is-invalid @enderror" value="{{ old('paid_on', now()->toDateString()) }}" required>
                @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label class="form-label" for="pay-account">{{ in_array($kind, Payment::KINDS_IN, true) ? __('Received into') : __('Paid from') }}<span class="app-req" aria-hidden="true">*</span></label>
                <select name="money_account_id" id="pay-account" class="form-select @error('money_account_id') is-invalid @enderror" required>
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach($accounts as $a)
                        <option value="{{ $a->id }}" data-currency="{{ $a->currency }}" @selected((int) old('money_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                    @endforeach
                </select>
                @error('money_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($accounts->isEmpty())
                    <div class="app-field-error">{{ __('There is no active money account yet.') }} <a href="{{ route('money-accounts.create') }}">{{ __('Add one') }}</a></div>
                @endif
            </div>

            <div class="col-md-4">
                <label class="form-label" for="pay-amount">{{ __('Amount') }}<span class="app-req" aria-hidden="true">*</span></label>
                <div class="input-group has-validation">
                    <input type="text" name="amount" id="pay-amount" inputmode="decimal" dir="ltr" class="form-control app-num @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required aria-describedby="pay-amount-help">
                    <span class="input-group-text app-num" data-amount-currency>—</span>
                    @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="form-text" id="pay-amount-help">{{ __('In the account\'s currency.') }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="pay-rate">{{ __('Rate to :book', ['book' => $book]) }}</label>
                <input type="text" name="rate" id="pay-rate" inputmode="decimal" dir="ltr" class="form-control app-num @error('rate') is-invalid @enderror" value="{{ old('rate') }}" aria-describedby="pay-rate-help">
                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text" id="pay-rate-help">{{ __(':book for one unit of the account\'s currency. Leave empty to take the FX rate of the day; type the rate obtained when it differs.', ['book' => $book]) }}</div>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="pay-reference">{{ __('Reference') }}</label>
                <input type="text" name="reference" id="pay-reference" maxlength="120" dir="ltr" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference') }}" placeholder="{{ __('Bank or transaction reference') }}">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </x-section>

    @php
        $partyLabel = match ($kind) {
            'client_receipt' => __('Client'),
            'supplier_payment' => __('Supplier'),
            'shipping_payment' => __('Shipping company'),
            default => __('Party'),
        };
        $partyRequired = in_array($kind, ['client_receipt', 'supplier_payment', 'shipping_payment'], true);
        $partyId = (int) old('party_id', $preset['party_id']);
    @endphp
    <x-section :title="__('Who and what it is for')" icon="link-45deg">
        <div class="row g-3">
            @if($kind === 'salary_payment')
                <div class="col-md-6">
                    <label class="form-label" for="pay-payroll">{{ __('Payroll run') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <select name="payroll_run_id" id="pay-payroll" class="form-select @error('payroll_run_id') is-invalid @enderror" required aria-describedby="pay-payroll-help">
                        <option value="">{{ __('Choose…') }}</option>
                        @foreach($payrollRuns as $run)
                            <option value="{{ $run->id }}" @selected((int) old('payroll_run_id', $preset['payroll_run_id']) === $run->id)>{{ $run->number }} · {{ $run->periodLabel() }} · {{ $run->currency }}</option>
                        @endforeach
                    </select>
                    @error('payroll_run_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="pay-payroll-help">{{ __('Only approved runs. Pay from an account in the run\'s currency.') }}</div>
                </div>
            @else
            <div class="col-md-6">
                <label class="form-label" for="pay-party">{{ $partyLabel }}@if($partyRequired)<span class="app-req" aria-hidden="true">*</span>@endif</label>
                <select name="party_id" id="pay-party" class="form-select @error('party_id') is-invalid @enderror" @required($partyRequired)>
                    <option value="">{{ $partyRequired ? __('Choose…') : __('None') }}</option>
                    @foreach($parties as $p)
                        <option value="{{ $p->id }}" @selected($partyId === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
                    @endforeach
                </select>
                @error('party_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            @endif

            @if($kind === 'client_receipt')
                <div class="col-md-6">
                    <label class="form-label" for="pay-pi">{{ __('Proforma invoice') }}</label>
                    <select name="proforma_invoice_id" id="pay-pi" class="form-select @error('proforma_invoice_id') is-invalid @enderror" data-follows-party aria-describedby="pay-pi-help">
                        <option value="">{{ __('None') }}</option>
                        @foreach($pis as $pi)
                            <option value="{{ $pi->id }}" data-party="{{ $pi->client_id }}" @selected((int) old('proforma_invoice_id', $preset['proforma_invoice_id']) === $pi->id)>{{ $pi->reference() }} · {{ $pi->currency }}</option>
                        @endforeach
                    </select>
                    @error('proforma_invoice_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text" id="pay-pi-help">{{ __('A receipt counts as paid on the PI only in the PI\'s currency.') }}</div>
                </div>
            @endif

            @if(in_array($kind, ['supplier_payment', 'expense'], true))
                <div class="col-md-6">
                    <label class="form-label" for="pay-order">{{ __('Supplier order') }}</label>
                    <select name="supplier_order_id" id="pay-order" class="form-select @error('supplier_order_id') is-invalid @enderror" @if($kind === 'supplier_payment') data-follows-party @endif>
                        <option value="">{{ __('None') }}</option>
                        @foreach($orders as $o)
                            <option value="{{ $o->id }}" data-party="{{ $o->supplier_id }}" @selected((int) old('supplier_order_id', $preset['supplier_order_id']) === $o->id)>{{ $o->number }} · {{ $o->currency }}</option>
                        @endforeach
                    </select>
                    @error('supplier_order_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif

            @if(in_array($kind, ['shipping_payment', 'expense'], true))
                <div class="col-md-6">
                    <label class="form-label" for="pay-container">{{ __('Container') }}</label>
                    <select name="container_id" id="pay-container" class="form-select @error('container_id') is-invalid @enderror">
                        <option value="">{{ __('None') }}</option>
                        @foreach($containers as $c)
                            <option value="{{ $c->id }}" @selected((int) old('container_id', $preset['container_id']) === $c->id)>{{ $c->container_no ?: '#'.$c->id }} · {{ $c->shipment?->number }}</option>
                        @endforeach
                    </select>
                    @error('container_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif

            @if(in_array($kind, ['expense', 'other_in', 'other_out'], true))
                <div class="col-md-6">
                    <label class="form-label" for="pay-counter">{{ $kind === 'other_in' ? __('Where the money comes from (account)') : __('What the money is for (account)') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <select name="counter_account_id" id="pay-counter" class="form-select @error('counter_account_id') is-invalid @enderror" required>
                        <option value="">{{ __('Choose…') }}</option>
                        @php
                            $typeLabels = ['expense' => __('Expenses'), 'income' => __('Income'), 'asset' => __('Assets'), 'liability' => __('Liabilities'), 'equity' => __('Equity')];
                            $typeOrder = $kind === 'other_in' ? ['income', 'liability', 'equity', 'asset', 'expense'] : ['expense', 'asset', 'liability', 'equity', 'income'];
                            $byType = $counterAccounts->groupBy('type');
                        @endphp
                        @foreach($typeOrder as $type)
                            @if($byType->has($type))
                                <optgroup label="{{ $typeLabels[$type] }}">
                                    @foreach($byType[$type] as $acc)
                                        <option value="{{ $acc->id }}" @selected((int) old('counter_account_id') === $acc->id)>{{ $acc->code }} — {{ $acc->displayName() }}</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        @endforeach
                    </select>
                    @error('counter_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif
        </div>
    </x-section>

    <x-section :title="__('Note and evidence')" icon="paperclip">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label" for="pay-note">{{ __('Note') }}</label>
                <textarea name="note" id="pay-note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label" for="pay-files">{{ __('Evidence (bank slip or screenshot)') }}</label>
                <input type="file" name="files[]" id="pay-files" multiple class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.webp" aria-describedby="pay-files-help">
                @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text" id="pay-files-help">{{ __('PDF or image files; you can choose several.') }}</div>
            </div>
        </div>
    </x-section>

    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Record payment') }}</button>
        <a href="{{ route('payments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
    // Offer only the chosen party's documents (the server checks the match anyway).
    (function () {
        const party = document.getElementById('pay-party');
        if (!party) return;
        const filter = () => document.querySelectorAll('select[data-follows-party] option[data-party]').forEach((option) => {
            const hide = party.value !== '' && option.dataset.party !== party.value;
            option.hidden = hide;
            if (hide && option.selected) option.parentElement.value = '';
        });
        party.addEventListener('change', filter);
        filter();
    })();
    // Show the chosen account's currency beside the amount.
    (function () {
        const account = document.getElementById('pay-account');
        const unit = document.querySelector('[data-amount-currency]');
        if (!account || !unit) return;
        const show = () => { unit.textContent = account.selectedOptions[0]?.dataset.currency || '—'; };
        account.addEventListener('change', show);
        show();
    })();
</script>
@endpush
