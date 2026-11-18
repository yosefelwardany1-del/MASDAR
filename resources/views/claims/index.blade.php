@extends('layouts.app')

@section('title', __('Claims'))

@section('content')
@php use App\Models\Claim; use App\Services\Accounting\Decimal; @endphp
<x-page-header :title="__('Claims & notes')" :subtitle="__('Short, defective or damaged goods: the claim against the factory, the answer to the client, and the notes that settle them.')">
    @can('claims.edit')
        <x-slot:actions><a href="{{ route('claims.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Open a claim') }}</a></x-slot:actions>
    @endcan
</x-page-header>
@include('claims._nav', ['active' => 'claims'])

<nav class="app-doc-chips mb-3" aria-label="{{ __('Status') }}">
    @foreach(['active' => __('Being worked on'), 'all' => __('All')] + collect(Claim::STATUSES)->mapWithKeys(fn ($s) => [$s => Claim::statusLabel($s)])->all() as $value => $label)
        <a class="app-doc-chip {{ $status === $value ? 'is-active' : '' }}" href="{{ route('claims.index', ['status' => $value]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

@if($claims->isEmpty())
    <div class="app-card">
        <x-empty icon="exclamation-octagon" :title="__('No claims here.')" :text="__('Open a claim when goods arrive short, defective or damaged; photos go with it.')">
            @can('claims.edit')<x-slot:action><a href="{{ route('claims.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Open a claim') }}</a></x-slot:action>
@endcan
        </x-empty>
    </div>
@else
    <x-section :title="__('Claims')" icon="exclamation-octagon" flush>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr>
                    <th scope="col">{{ __('Claim') }}</th><th scope="col">{{ __('About') }}</th>
                    @if($seesSuppliers)<th scope="col">{{ __('Factory') }}</th>@endif
                    <th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('Opened') }}</th>
                    <th scope="col" class="text-end">{{ __('Claimed') }}</th><th scope="col">{{ __('Status') }}</th>
                </tr></thead>
                <tbody>
                @foreach($claims as $c)
                    @php $st = $c->status(); @endphp
                    <tr>
                        <td><a href="{{ route('claims.show', $c) }}" class="fw-semibold app-ltr">{{ $c->number }}</a></td>
                        <td>{{ $kinds[$c->kind] ?? $c->kind }}<div class="small text-muted text-truncate" style="max-inline-size: 22rem">{{ $c->description }}</div></td>
                        @if($seesSuppliers)<td><bdi>{{ $c->supplier?->name ?? '—' }}</bdi>@if($c->supplierOrder)<div class="small text-muted app-ltr">{{ $c->supplierOrder->number }}</div>@endif</td>@endif
                        <td><bdi>{{ $c->client?->name ?? '—' }}</bdi>@if($c->proformaInvoice)<div class="small text-muted app-ltr">{{ $c->proformaInvoice->reference() }}</div>@endif</td>
                        <td><span class="app-num">{{ $c->opened_on->format('Y-m-d') }}</span></td>
                        <td class="text-end">@if($c->claimed_amount !== null)<span class="app-num">{{ Decimal::format((string) $c->claimed_amount) }}</span> <span class="app-unit">{{ $c->currency }}</span>@else — @endif</td>
                        <td><x-status :tone="Claim::statusTone($st)">{{ Claim::statusLabel($st) }}</x-status></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </x-section>
    <div class="mt-3">{{ $claims->links() }}</div>
@endif
@endsection
