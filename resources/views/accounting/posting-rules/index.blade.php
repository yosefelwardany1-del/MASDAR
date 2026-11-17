@extends('layouts.app')

@section('title', __('Posting rules'))

@section('content')
<h1 class="h4 mb-3">{{ __('Accounting') }}</h1>
@include('accounting._nav', ['active' => 'rules'])

<div class="alert alert-info small">
    {{ __('Each automatic posting uses these accounts. For a payment, the money side is the money account\'s own ledger account (set on the Payments screen); expenses and other money in or out use the counter account chosen on the payment. The rules start as for a trader who buys and resells: for an agent, point the client invoice\'s credit at commission income (spec Q-A2). MASDAR\'s accountant confirms them.') }}
</div>

@php $editable = auth()->user()->can('accounting.edit'); @endphp
<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Event') }}</th><th>{{ __('Debit') }}</th><th>{{ __('Credit') }}</th><th>{{ __('What it is') }}</th><th></th>
            </tr></thead>
            <tbody>
            @foreach($rules as $rule)
                @php $ruleSides = $sides[$rule->event] ?? ['debit', 'credit']; @endphp
                <tr>
                    <td>
                        <div class="fw-semibold">{{ \App\Services\Accounting\PostingService::eventLabel($rule->event) }}</div>
                        <div class="small text-muted app-ltr">{{ $rule->event }}</div>
                    </td>
                    @foreach(['debit', 'credit'] as $side)
                        @php $current = $side === 'debit' ? $rule->debitAccount : $rule->creditAccount; @endphp
                        <td style="min-inline-size: 220px">
                            @if(! in_array($side, $ruleSides, true))
                                <span class="text-muted small"><i class="bi bi-wallet2 me-1"></i>{{ __('The money account') }}</span>
                            @elseif($editable)
                                <select name="{{ $side }}_account_id" form="rule-{{ $rule->id }}" class="form-select form-select-sm {{ $current ? '' : 'is-invalid' }}">
                                    <option value="">{{ __('— not set —') }}</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" @selected($current?->id === $account->id)>{{ $account->label() }}</option>
                                    @endforeach
                                </select>
                            @else
                                {{ $current?->label() ?? __('— not set —') }}
                            @endif
                        </td>
                    @endforeach
                    <td class="small">
                        @if($editable)
                            <textarea name="description" form="rule-{{ $rule->id }}" rows="2" class="form-control form-control-sm" dir="ltr">{{ $rule->description }}</textarea>
                        @else
                            {{ $rule->description }}
                        @endif
                    </td>
                    <td class="text-end">
                        @if($editable)
                            <form id="rule-{{ $rule->id }}" method="POST" action="{{ route('accounting.posting-rules.update', $rule) }}">
                                @csrf @method('PUT')
                                <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection
