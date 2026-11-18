@extends('layouts.app')

@section('title', __('Exchange revaluation'))

@php use App\Services\Accounting\Decimal; $f = fn ($v) => Decimal::format((string) $v); @endphp

@section('content')
<x-page-header :title="__('Exchange revaluation')" :eyebrow="__('Accounting')"
               :subtitle="__('At a month end, every balance in another currency — bank, client and supplier balances — is restated at that day\'s rate; the difference is an exchange gain or loss.')" />
@include('accounting._nav', ['active' => 'revaluation'])

<form method="GET" class="app-filterbar app-filterbar--fit" aria-label="{{ __('Date') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow"><label class="form-label" for="date">{{ __('Revalue at') }}</label><input id="date" type="date" name="date" value="{{ $date }}" class="form-control form-control-sm"></div>
    <div class="app-filterbar__actions"><button class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>{{ __('Preview') }}</button></div>
</form>

@if($error)
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ $error }}</div></div>
@else
    @php $net = Decimal::sum(array_column($rows, 'difference')); @endphp
    <x-section :title="__('What it would post')" icon="currency-exchange" flush>
        <div class="table-responsive">
            <table class="table align-middle mb-0 app-ledger">
                <thead><tr>
                    <th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Currency') }}</th>
                    <th scope="col" class="text-end">{{ __('Balance in currency') }}</th><th scope="col" class="text-end">{{ __('Rate') }}</th>
                    <th scope="col" class="text-end">{{ __('Carried (:book)', ['book' => $book]) }}</th><th scope="col" class="text-end">{{ __('At the rate (:book)', ['book' => $book]) }}</th><th scope="col" class="text-end">{{ __('Difference') }}</th>
                </tr></thead>
                <tbody>
                @forelse($rows as $r)
                    <tr>
                        <td>{{ $r['account']->label() }}</td>
                        <td><bdi>{{ $r['party'] ?? '—' }}</bdi></td>
                        <td><span class="app-ltr">{{ $r['currency'] }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['balance']) }}</span></td>
                        <td class="text-end"><span class="app-num">{{ rtrim(rtrim($r['rate'], '0'), '.') }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['carried']) }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['revalued']) }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig {{ Decimal::cmp($r['difference'], '0') < 0 ? 'app-fig--neg' : 'app-fig--in' }}">{{ $f($r['difference']) }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="p-0"><x-empty icon="currency-exchange" :title="__('Nothing to revalue on this date.')" :text="__('Every balance in another currency is already at this rate, or there is none.')" /></td></tr>
                @endforelse
                </tbody>
                @if(count($rows))
                    <tfoot><tr class="app-ledger__total"><th scope="row" colspan="7">{{ Decimal::isPositive($net) ? __('Exchange gain') : __('Exchange loss') }}</th><td class="app-figcell"><span class="app-num app-fig">{{ $f(Decimal::abs($net)) }}</span></td></tr></tfoot>
                @endif
            </table>
        </div>
    </x-section>
    @if(count($rows))
        @can('accounting.approve')
            <form method="POST" action="{{ route('accounting.revaluation.post') }}" class="mt-3" onsubmit="return confirm(@js(__('Post this revaluation?')));">
                @csrf <input type="hidden" name="date" value="{{ $date }}">
                <button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Post the revaluation') }}</button>
                <span class="small text-muted ms-2">{{ __('The next revaluation carries on from this one; reverse it on the Journal screen if needed.') }}</span>
            </form>
        @else
            <p class="app-note mt-3"><i class="bi bi-lock" aria-hidden="true"></i><span>{{ __('A manager posts the revaluation.') }}</span></p>
        @endcan
    @endif
@endif
@endsection
