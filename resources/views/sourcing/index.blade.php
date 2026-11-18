@extends('layouts.app')

@section('title', __('Factory quotes'))

@section('content')
@php use App\Models\SourcingRequest; use App\Services\Accounting\Decimal; @endphp
<x-page-header :title="__('Factory quotes')" :subtitle="__('Ask several factories, compare their price, minimum order and lead time, and put the one chosen on the client\'s quotation.')">
    @can('sourcing.edit')
        <x-slot:actions><a href="{{ route('sourcing.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New request') }}</a></x-slot:actions>
    @endcan
</x-page-header>

<nav class="app-doc-chips mb-3" aria-label="{{ __('Status') }}">
    @foreach(['open' => __('Collecting quotes'), 'chosen' => __('Factory chosen'), 'closed' => __('Closed'), 'all' => __('All')] as $value => $label)
        <a class="app-doc-chip {{ $status === $value ? 'is-active' : '' }}" href="{{ route('sourcing.index', ['status' => $value]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if($requests->isEmpty())
    <div class="app-card"><x-empty icon="ui-checks-grid" :title="__('No requests here.')" :text="__('When a client asks for something new, open a request and record each factory\'s quote as it comes in.')" /></div>
@else
    <x-section :title="__('Requests')" icon="ui-checks-grid" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th scope="col">{{ __('Request') }}</th><th scope="col">{{ __('What') }}</th><th scope="col">{{ __('Client') }}</th>
                    <th scope="col" class="text-end">{{ __('Quotes') }}</th><th scope="col">{{ __('Needed by') }}</th><th scope="col">{{ __('Chosen') }}</th><th scope="col">{{ __('Status') }}</th>
                </tr></thead>
                <tbody>
                @foreach($requests as $r)
                    <tr>
                        <td><a href="{{ route('sourcing.show', $r) }}" class="fw-semibold app-ltr">{{ $r->number }}</a></td>
                        <td><span class="d-inline-block text-truncate" style="max-inline-size: 22rem" dir="auto">{{ $r->description }}</span></td>
                        <td><bdi>{{ $r->client?->name ?? '—' }}</bdi></td>
                        <td class="text-end"><span class="app-num">{{ $r->quotes_count }}</span></td>
                        <td><span class="app-num">{{ $r->needed_by?->format('Y-m-d') ?? '—' }}</span></td>
                        <td>@if($r->chosenQuote)<bdi>{{ $r->chosenQuote->supplier?->name }}</bdi> <span class="app-num small text-muted">{{ Decimal::format((string) $r->chosenQuote->unit_price) }} {{ $r->chosenQuote->currency }}</span>@else — @endif</td>
                        <td><x-status :tone="SourcingRequest::statusTone($r->status)">{{ SourcingRequest::statusLabel($r->status) }}</x-status></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
    <div class="mt-3">{{ $requests->links() }}</div>
@endif
@endsection
