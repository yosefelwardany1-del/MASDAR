@extends('layouts.app')

@section('title', __('Money accounts'))

@php
    use App\Models\MoneyAccount;
    use App\Services\Accounting\Decimal;
    use App\Services\Finance\MoneyFormat;

    // Each account's share of the largest balance in its own currency, for the bar in its row.
    $maxByCurrency = $accounts->groupBy('currency')->map(fn ($group) => $group
        ->map(fn ($a) => Decimal::abs($balances[$a->id] ?? '0'))
        ->reduce(fn ($carry, $v) => Decimal::cmp($v, $carry) > 0 ? $v : $carry, '0'));
@endphp

@section('content')
<x-page-header :title="__('Money accounts')" :subtitle="__('Bank accounts, e-wallets, cash and agents, each in its own currency.')">
    @can('payments.edit')
        <x-slot:actions>
            <a href="{{ route('money-accounts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New money account') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

@include('payments._nav', ['active' => 'accounts'])

@if($accounts->isEmpty())
    <div class="app-card">
        <x-empty icon="bank" :title="__('No money accounts yet.')" :text="__('Add the bank accounts, e-wallets and cash boxes MASDAR receives and pays from.')">
            @can('payments.edit')
                <x-slot:action><a href="{{ route('money-accounts.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New money account') }}</a></x-slot:action>
            @endcan
        </x-empty>
    </div>
@else
    <x-section :title="__('Cash position')" icon="wallet2" :subtitle="__('What the accounts hold, per currency')" class="mb-4">
        @include('money-accounts._currency-bars', ['inBook' => $inBook, 'book' => $book])
    </x-section>

    <x-section :title="__('Accounts')" icon="bank" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('Ledger account') }}</th>
                    <th scope="col" class="text-end">{{ __('Balance') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                </tr></thead>
                @foreach($accounts->groupBy('currency') as $currency => $group)
                    <tbody>
                        <tr class="app-groupband">
                            <th scope="rowgroup" colspan="2"><span class="app-ltr">{{ $currency }}</span> · {{ trans_choice('{1} one account|[2,*] :count accounts', $group->count(), ['count' => $group->count()]) }}</th>
                            <td class="text-end"><span class="app-num app-fig {{ Decimal::cmp($position[$currency] ?? '0', '0') < 0 ? 'app-fig--neg' : '' }}">{{ MoneyFormat::amount($position[$currency] ?? '0') }}</span><span class="app-unit">{{ $currency }}</span></td>
                            <td colspan="2"></td>
                        </tr>
                        @foreach($group as $a)
                            @php
                                $balance = $balances[$a->id] ?? '0';
                                $negative = Decimal::cmp($balance, '0') < 0;
                                $max = $maxByCurrency[$currency] ?? '0';
                                $width = Decimal::isPositive($max) ? round((float) Decimal::abs($balance) / (float) $max * 100, 1) : 0;
                            @endphp
                            <tr class="{{ $a->active ? '' : 'text-muted' }}">
                                <td>
                                    <a href="{{ route('money-accounts.show', $a) }}" class="fw-semibold">{{ $a->name }}</a>
                                    <div class="small text-muted">{{ MoneyAccount::kindLabel($a->kind) }} · {{ MoneyAccount::locationLabel($a->location) }}</div>
                                </td>
                                <td>@if($a->ledgerAccount)<span class="app-ltr text-muted small">{{ $a->ledgerAccount->code }}</span> {{ $a->ledgerAccount->displayName() }}@else<x-status tone="due" icon="exclamation-triangle">{{ __('Not set') }}</x-status>@endif</td>
                                <td class="text-end" style="min-inline-size: 11rem">
                                    <span class="app-num app-fig fw-semibold {{ $negative ? 'app-fig--neg' : '' }}">{{ MoneyFormat::amount($balance) }}</span><span class="app-unit">{{ $a->currency }}</span>
                                    @if($group->count() > 1)
                                        <span class="app-minibar {{ $negative ? 'app-minibar--neg' : '' }}" aria-hidden="true"><span style="inline-size: {{ $width }}%"></span></span>
                                    @endif
                                </td>
                                <td>@if($a->active)<x-status tone="done">{{ __('Active') }}</x-status>@else<x-status tone="skip">{{ __('Inactive') }}</x-status>@endif</td>
                                <td class="text-end">
                                    <span class="app-rowactions">
                                        <a href="{{ route('money-accounts.show', $a) }}" class="btn btn-sm btn-light" title="{{ __('View') }}" aria-label="{{ __('View') }}: {{ $a->name }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                                        @can('payments.edit')
                                            <a href="{{ route('money-accounts.edit', $a) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}: {{ $a->name }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                        @endcan
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                @endforeach
            </table>
        </div>
    </x-section>
    <p class="app-note mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('A balance is the sum of the account\'s payments that count: money in, and money out once approved (when approval is required).') }}</span></p>
@endif
@endsection
