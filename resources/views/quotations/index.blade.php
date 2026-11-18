@extends('layouts.app')

@section('title', __('Quotations'))

@section('content')
@php use App\Models\Quotation; use App\Services\Accounting\Decimal; @endphp
<x-page-header :title="__('Quotations')" :subtitle="__('Price offers to clients before the PI. An accepted quotation becomes a draft PI in one step.')">
    @can('quotations.edit')
        <x-slot:actions><a href="{{ route('quotations.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New quotation') }}</a></x-slot:actions>
    @endcan
</x-page-header>

<nav class="app-doc-chips mb-3" aria-label="{{ __('Status') }}">
    @foreach(['all' => __('All'), 'draft' => __('Draft'), 'sent' => __('Sent'), 'expired' => __('Expired'), 'accepted' => __('Accepted'), 'declined' => __('Declined')] as $value => $label)
        <a class="app-doc-chip {{ $status === $value ? 'is-active' : '' }}" href="{{ route('quotations.index', ['status' => $value]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if($quotations->isEmpty())
    <div class="app-card">
        <x-empty icon="chat-square-quote" :title="__('No quotations yet.')" :text="__('Quote a client their prices first; when they agree, the quotation becomes the PI.')">
            @can('quotations.edit')<x-slot:action><a href="{{ route('quotations.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New quotation') }}</a></x-slot:action>
@endcan
        </x-empty>
    </div>
@else
    <x-section :title="__('Quotations')" icon="chat-square-quote" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th scope="col">{{ __('Quotation') }}</th><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Valid until') }}</th><th scope="col" class="text-end">{{ __('Items') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('PI') }}</th></tr></thead>
                <tbody>
                @foreach($quotations as $q)
                    @php $st = $q->displayStatus(); @endphp
                    <tr>
                        <td><a href="{{ route('quotations.show', $q) }}" class="fw-semibold app-ltr">{{ $q->isDraft() ? __('Draft') : $q->number }}</a></td>
                        <td><bdi>{{ $q->client?->name }}</bdi></td>
                        <td><span class="app-num">{{ $q->quoted_on->format('Y-m-d') }}</span></td>
                        <td><span class="app-num {{ $st === 'expired' ? 'text-danger' : '' }}">{{ $q->valid_until->format('Y-m-d') }}</span></td>
                        <td class="text-end"><span class="app-num">{{ $q->lines_count }}</span></td>
                        <td><x-status :tone="Quotation::statusTone($st)">{{ Quotation::statusLabel($st) }}</x-status></td>
                        <td>@if($q->proformaInvoice)<a href="{{ route('proforma-invoices.show', $q->proforma_invoice_id) }}" class="app-ltr">{{ $q->proformaInvoice->reference() }}</a>@else — @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
    <div class="mt-3">{{ $quotations->links() }}</div>
@endif
@endsection
