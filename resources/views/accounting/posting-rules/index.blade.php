@extends('layouts.app')

@section('title', __('Posting rules'))

@section('content')
<x-page-header :title="__('Posting rules')" :eyebrow="__('Accounting')" :subtitle="__('Which accounts each automatic posting debits and credits.')" />
@include('accounting._nav', ['active' => 'rules'])

<div class="alert alert-info small d-flex gap-2">
    <i class="bi bi-info-circle" aria-hidden="true"></i>
    <span>{{ __('Each automatic posting uses these accounts. For a payment, the money side is the money account\'s own ledger account (set on the Payments screen); expenses and other money in or out use the counter account chosen on the payment. The rules start as for a trader who buys and resells: for an agent, point the client invoice\'s credit at commission income. MASDAR\'s accountant confirms them.') }}</span>
</div>

@php $editable = auth()->user()->can('accounting.edit'); @endphp
<x-section :title="__('Rules')" icon="signpost-split" flush>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Debit') }}</th><th scope="col">{{ __('Credit') }}</th><th scope="col">{{ __('What it is') }}</th><th scope="col"><span class="visually-hidden">{{ __('Save') }}</span></th>
            </tr></thead>
            <tbody>
            @foreach($rules as $rule)
                @php $ruleSides = $sides[$rule->event] ?? ['debit', 'credit']; $eventLabel = \App\Services\Accounting\PostingService::eventLabel($rule->event); @endphp
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $eventLabel }}</div>
                        <div class="app-code app-ltr">{{ $rule->event }}</div>
                    </td>
                    @foreach(['debit', 'credit'] as $side)
                        @php $current = $side === 'debit' ? $rule->debitAccount : $rule->creditAccount; @endphp
                        <td class="app-rule-side">
                            @if(! in_array($side, $ruleSides, true))
                                <span class="app-rule-side__money"><i class="bi bi-wallet2" aria-hidden="true"></i>{{ __('The money account') }}</span>
                            @elseif($editable)
                                <select name="{{ $side }}_account_id" form="rule-{{ $rule->id }}" class="form-select form-select-sm {{ $current ? '' : 'is-invalid' }}" aria-label="{{ $eventLabel }}: {{ $side === 'debit' ? __('Debit') : __('Credit') }}">
                                    <option value="">{{ __('— not set —') }}</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}" @selected($current?->id === $account->id)>{{ $account->label() }}</option>
                                    @endforeach
                                </select>
                                @unless($current)<div class="app-field-error">{{ __('Not set') }}</div>@endunless
                            @else
                                @if($current){{ $current->label() }}@else<x-status tone="late">{{ __('— not set —') }}</x-status>@endif
                            @endif
                        </td>
                    @endforeach
                    <td class="small" style="min-inline-size: 14rem">
                        @if($editable)
                            <textarea name="description" form="rule-{{ $rule->id }}" rows="2" class="form-control form-control-sm" dir="ltr" aria-label="{{ $eventLabel }}: {{ __('What it is') }}">{{ $rule->description }}</textarea>
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
</x-section>
@endsection
