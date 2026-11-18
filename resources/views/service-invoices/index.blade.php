@extends('layouts.app')

@section('title', __('Service invoices'))

@php use App\Services\Accounting\Decimal; @endphp

@section('content')
<x-page-header :title="__('Service invoices')" :subtitle="__('What MASDAR charges besides goods: sourcing commission, inspection, factory visits, samples, freight recharged. Posted to the books like any invoice.')">
    @can('service_invoices.edit')<x-slot:actions><a href="{{ route('service-invoices.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New invoice') }}</a></x-slot:actions>
    @endcan
</x-page-header>
<x-section :title="__('Invoices')" icon="receipt" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Invoice') }}</th><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($invoices as $i)
                <tr>
                    <td><a href="{{ route('service-invoices.show', $i) }}" class="fw-semibold app-ltr">{{ $i->isDraft() ? __('Draft') : $i->number }}</a></td>
                    <td><bdi>{{ $i->client?->name }}</bdi></td>
                    <td><span class="app-num">{{ $i->issued_on?->format('Y-m-d') ?? '—' }}</span></td>
                    <td class="text-end"><span class="app-num">{{ Decimal::format((string) ($i->lines_sum_amount ?? 0)) }}</span> <span class="app-unit">{{ $i->currency }}</span></td>
                    <td><x-status :tone="['issued' => 'done', 'void' => 'skip'][$i->status] ?? 'neutral'">{{ ['draft' => __('Draft'), 'issued' => __('Issued'), 'void' => __('Void (note)')][$i->status] }}</x-status></td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="receipt" :title="__('No service invoices yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
<div class="mt-3">{{ $invoices->links() }}</div>
@endsection
