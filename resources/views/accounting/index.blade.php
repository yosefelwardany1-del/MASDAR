@extends('layouts.app')

@section('title', __('Chart of accounts'))

@section('content')
@php
    use App\Services\Accounting\Decimal;
    $fmt = fn ($v) => Decimal::format($v);
@endphp
<x-page-header :title="__('Chart of accounts')" :eyebrow="__('Accounting')"
               :subtitle="__('The chart is a starting point for MASDAR\'s accountant (spec Q-A4). Headings group sub-accounts and are never posted to. Balances are in :book, shown positive when normal for the account\'s type.', ['book' => $book])">
    @can('accounting.edit')
        <x-slot:actions>
            <a href="{{ route('accounting.accounts.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New account') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

@include('accounting._nav', ['active' => 'chart'])

<x-section :title="__('Accounts')" icon="diagram-3" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-chart">
            <thead><tr>
                <th scope="col">{{ __('Code') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Type') }}</th><th scope="col">{{ __('Currency') }}</th>
                <th scope="col">{{ __('Takes postings') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col" class="text-end">{{ __('Balance (:book)', ['book' => $book]) }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php $a = $row['account']; $negative = Decimal::cmp($row['amount'], '0') < 0; @endphp
                <tr class="{{ $a->is_postable ? '' : 'app-chart__heading' }}">
                    <td><span class="app-num {{ $a->is_postable ? 'text-muted' : '' }}">{{ $a->code }}</span></td>
                    <td class="app-chart__name" style="--depth: {{ $row['depth'] }}">{{ $a->displayName() }}</td>
                    <td class="small">{{ $a->typeLabel() }}</td>
                    <td class="small">@if($a->currency)<span class="app-ltr">{{ $a->currency }}</span>@else<span class="text-muted">{{ __('Any') }}</span>@endif</td>
                    <td>@if($a->is_postable)<i class="bi bi-check-lg text-success" aria-hidden="true"></i><span class="visually-hidden">{{ __('Yes') }}</span>@else<span class="app-chart__tag">{{ __('Heading') }}</span>@endif</td>
                    <td>@if($a->active)<x-status tone="done">{{ __('Active') }}</x-status>@else<x-status tone="skip">{{ __('Inactive') }}</x-status>@endif</td>
                    <td class="text-end"><span class="app-num app-fig {{ $negative ? 'app-fig--neg' : '' }} {{ $a->is_postable ? '' : 'fw-semibold' }}">{{ $fmt($row['amount']) }}</span></td>
                    <td class="text-end">
                        <span class="app-rowactions">
                            @if($a->is_postable)
                                @can('finance_reports.view')
                                    <a href="{{ route('finance-reports.account-ledger', ['account_id' => $a->id]) }}" class="btn btn-sm btn-light" title="{{ __('Account ledger') }}" aria-label="{{ __('Account ledger') }}: {{ $a->code }}"><i class="bi bi-list-ul" aria-hidden="true"></i></a>
                                @endcan
                            @endif
                            @can('accounting.edit')
                                <a href="{{ route('accounting.accounts.edit', $a) }}" class="btn btn-sm btn-light" title="{{ __('Edit') }}" aria-label="{{ __('Edit') }}: {{ $a->code }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                            @endcan
                        </span>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="p-0"><x-empty icon="diagram-3" :title="__('No accounts yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
