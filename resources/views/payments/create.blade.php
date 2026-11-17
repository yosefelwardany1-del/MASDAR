@extends('layouts.app')

@section('title', __('Record a payment'))

@php use App\Models\Payment; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Record a payment') }}</h1>
    @if($canTransfer)
        <a href="{{ route('payments.transfer.create') }}" class="btn btn-light"><i class="bi bi-arrow-left-right me-1"></i>{{ __('Transfer between accounts') }}</a>
    @endif
</div>

<ul class="nav nav-tabs mb-0">
    @foreach($kinds as $k)
        <li class="nav-item"><a class="nav-link {{ $k === $kind ? 'active' : '' }}" href="{{ route('payments.create', ['kind' => $k]) }}">{{ Payment::kindLabel($k) }}</a></li>
    @endforeach
</ul>

<div class="app-card p-4" style="border-start-start-radius:0">
    @if($errors->any())
        <div class="alert alert-danger py-2">{{ __('Please correct the fields marked below.') }}</div>
    @endif
    @if(in_array($kind, Payment::KINDS_OUT, true) && $approvalRequired)
        <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1"></i>{{ __('Money out waits for a manager\'s approval before it counts in the balances and the ledger.') }}</div>
    @endif

    <form method="POST" action="{{ route('payments.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="hidden" name="kind" value="{{ $kind }}">
        @error('kind')<div class="alert alert-danger py-2">{{ $message }}</div>@enderror

        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">{{ __('Date') }} <span class="text-danger">*</span></label>
                <input type="date" name="paid_on" class="form-control @error('paid_on') is-invalid @enderror" value="{{ old('paid_on', now()->toDateString()) }}" required>
                @error('paid_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-8">
                <label class="form-label">{{ in_array($kind, Payment::KINDS_IN, true) ? __('Received into') : __('Paid from') }} <span class="text-danger">*</span></label>
                <select name="money_account_id" class="form-select @error('money_account_id') is-invalid @enderror" required>
                    <option value="">{{ __('Choose…') }}</option>
                    @foreach($accounts as $a)
                        <option value="{{ $a->id }}" @selected((int) old('money_account_id') === $a->id)>{{ $a->label() }} — {{ \App\Models\MoneyAccount::locationLabel($a->location) }}</option>
                    @endforeach
                </select>
                @error('money_account_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($accounts->isEmpty())
                    <div class="form-text text-danger">{{ __('There is no active money account yet.') }} <a href="{{ route('money-accounts.create') }}">{{ __('Add one') }}</a></div>
                @endif
            </div>

            <div class="col-md-4">
                <label class="form-label">{{ __('Amount') }} <span class="text-danger">*</span></label>
                <input type="text" name="amount" inputmode="decimal" dir="ltr" class="form-control @error('amount') is-invalid @enderror" value="{{ old('amount') }}" required>
                <div class="form-text">{{ __('In the account\'s currency.') }}</div>
                @error('amount')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('Rate to :book', ['book' => $book]) }}</label>
                <input type="text" name="rate" inputmode="decimal" dir="ltr" class="form-control @error('rate') is-invalid @enderror" value="{{ old('rate') }}">
                <div class="form-text">{{ __(':book for one unit of the account\'s currency. Leave empty to take the FX rate of the day; type the rate obtained when it differs.', ['book' => $book]) }}</div>
                @error('rate')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-4">
                <label class="form-label">{{ __('Reference') }}</label>
                <input type="text" name="reference" maxlength="120" dir="ltr" class="form-control @error('reference') is-invalid @enderror" value="{{ old('reference') }}" placeholder="{{ __('Bank or transaction reference') }}">
                @error('reference')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

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
            <div class="col-md-6">
                <label class="form-label">{{ $partyLabel }} @if($partyRequired)<span class="text-danger">*</span>@endif</label>
                <select name="party_id" id="pay-party" class="form-select @error('party_id') is-invalid @enderror" @required($partyRequired)>
                    <option value="">{{ $partyRequired ? __('Choose…') : __('None') }}</option>
                    @foreach($parties as $p)
                        <option value="{{ $p->id }}" @selected($partyId === $p->id)>{{ $p->name }} ({{ $p->code }})</option>
                    @endforeach
                </select>
                @error('party_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            @if($kind === 'client_receipt')
                <div class="col-md-6">
                    <label class="form-label">{{ __('Proforma invoice') }}</label>
                    <select name="proforma_invoice_id" class="form-select @error('proforma_invoice_id') is-invalid @enderror" data-follows-party>
                        <option value="">{{ __('None') }}</option>
                        @foreach($pis as $pi)
                            <option value="{{ $pi->id }}" data-party="{{ $pi->client_id }}" @selected((int) old('proforma_invoice_id', $preset['proforma_invoice_id']) === $pi->id)>{{ $pi->reference() }} · {{ $pi->currency }}</option>
                        @endforeach
                    </select>
                    <div class="form-text">{{ __('A receipt counts as paid on the PI only in the PI\'s currency.') }}</div>
                    @error('proforma_invoice_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            @endif

            @if(in_array($kind, ['supplier_payment', 'expense'], true))
                <div class="col-md-6">
                    <label class="form-label">{{ __('Supplier order') }}</label>
                    <select name="supplier_order_id" class="form-select @error('supplier_order_id') is-invalid @enderror" @if($kind === 'supplier_payment') data-follows-party @endif>
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
                    <label class="form-label">{{ __('Container') }}</label>
                    <select name="container_id" class="form-select @error('container_id') is-invalid @enderror">
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
                    <label class="form-label">{{ $kind === 'other_in' ? __('Where the money comes from (account)') : __('What the money is for (account)') }} <span class="text-danger">*</span></label>
                    <select name="counter_account_id" class="form-select @error('counter_account_id') is-invalid @enderror" required>
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

            <div class="col-12">
                <label class="form-label">{{ __('Note') }}</label>
                <textarea name="note" rows="2" class="form-control @error('note') is-invalid @enderror">{{ old('note') }}</textarea>
                @error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-12">
                <label class="form-label">{{ __('Evidence (bank slip or screenshot)') }}</label>
                <input type="file" name="files[]" multiple class="form-control @error('files') is-invalid @enderror @error('files.*') is-invalid @enderror" accept=".pdf,.jpg,.jpeg,.png,.webp">
                @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Record payment') }}</button>
            <a href="{{ route('payments.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
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
</script>
@endpush
