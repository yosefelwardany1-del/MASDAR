@extends('layouts.portal')

@section('title', __('My statement'))

@php use App\Services\Accounting\Decimal; $f = fn ($v) => Decimal::format((string) $v); @endphp

@section('content')
<x-page-header :title="__('My statement')" :subtitle="__('Invoices, payments and credits over the last year, in :book. A positive balance is what you owe us.', ['book' => $book])">
    <x-slot:actions><button type="button" class="btn btn-light" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</button></x-slot:actions>
</x-page-header>
<x-section :title="$party->name" icon="person-lines-fill" flush>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0 app-ledger">
            <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Description') }}</th><th scope="col" class="text-end">{{ __('Debit') }}</th><th scope="col" class="text-end">{{ __('Credit') }}</th><th scope="col" class="text-end">{{ __('Balance') }}</th></tr></thead>
            <tbody>
                <tr class="app-ledger__bf"><td colspan="4">{{ __('Opening balance') }}</td><td class="app-figcell"><span class="app-num app-fig">{{ $f($report['opening']) }}</span></td></tr>
                @foreach($report['rows'] as $r)
                    <tr><td><span class="app-num">{{ $r['entry_date'] }}</span></td><td>{{ $r['memo'] ?: $r['description'] }}</td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::isZero($r['debit']) ? '' : $f($r['debit']) }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ Decimal::isZero($r['credit']) ? '' : $f($r['credit']) }}</span></td>
                        <td class="app-figcell"><span class="app-num app-fig">{{ $f($r['running']) }}</span></td></tr>
                @endforeach
            </tbody>
            <tfoot><tr class="app-ledger__total"><th scope="row" colspan="4">{{ __('Closing balance') }}</th><td class="app-figcell"><span class="app-num app-fig">{{ $f($report['closing']) }}</span></td></tr></tfoot>
        </table>
    </div>
</x-section>
@endsection
