@extends('layouts.app')

@php
    use App\Models\TradeNote;
    use App\Services\Accounting\Decimal;
    $title = $kind === 'credit' ? __('Credit notes to clients') : __('Debit notes on factories');
@endphp

@section('title', $title)

@section('content')
<x-page-header :title="__('Claims & notes')" :subtitle="$kind === 'credit'
    ? __('Money given back to a client on a PI: they owe that much less. Issued by a manager, posted to the books.')
    : __('Money claimed back from a factory on a purchase order: MASDAR owes that much less. Issued by a manager, posted to the books.')">
    @can('claims.edit')
        <x-slot:actions><a href="{{ route('trade-notes.create', ['kind' => $kind]) }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ $kind === 'credit' ? __('New credit note') : __('New debit note') }}</a></x-slot:actions>
    @endcan
</x-page-header>
@include('claims._nav', ['active' => $kind])

@if($notes->isEmpty())
    <div class="app-card"><x-empty icon="arrow-left-right" :title="__('No notes yet.')" :text="__('A note usually starts from a claim: open the claim, then settle it with a note.')" /></div>
@else
    <x-section :title="$title" icon="arrow-left-right" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th scope="col">{{ __('Note') }}</th><th scope="col">{{ $kind === 'credit' ? __('Client') : __('Factory') }}</th>
                    <th scope="col">{{ $kind === 'credit' ? __('PI') : __('Purchase order') }}</th><th scope="col">{{ __('Claim') }}</th>
                    <th scope="col">{{ __('Date') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th>
                </tr></thead>
                <tbody>
                @foreach($notes as $n)
                    <tr>
                        <td><a href="{{ route('trade-notes.show', $n) }}" class="fw-semibold app-ltr">{{ $n->isDraft() ? __('Draft') : $n->number }}</a></td>
                        <td><bdi>{{ $n->party?->name }}</bdi></td>
                        <td><span class="app-ltr">{{ $kind === 'credit' ? $n->proformaInvoice?->reference() : $n->supplierOrder?->number }}</span></td>
                        <td>@if($n->claim)<a href="{{ route('claims.show', $n->claim) }}" class="app-ltr">{{ $n->claim->number }}</a>@else — @endif</td>
                        <td><span class="app-num">{{ $n->issued_on?->format('Y-m-d') ?? '—' }}</span></td>
                        <td class="text-end"><span class="app-num">{{ Decimal::format((string) $n->lines_sum_amount) }}</span> <span class="app-unit">{{ $n->currency }}</span></td>
                        <td><x-status :tone="TradeNote::statusTone($n->status)">{{ TradeNote::statusLabel($n->status) }}</x-status></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
    <div class="mt-3">{{ $notes->links() }}</div>
@endif
@endsection
