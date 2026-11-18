@extends('layouts.app')

@section('title', __('Samples'))

@php use App\Models\Sample; @endphp

@section('content')
<x-page-header :title="__('Samples')" :subtitle="__('Each sample from the request through the factory to the client\'s answer, with the courier\'s tracking number.')">
    @can('samples.edit')<x-slot:actions><a href="{{ route('samples.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New sample') }}</a></x-slot:actions>
    @endcan
</x-page-header>
<nav class="app-doc-chips mb-3" aria-label="{{ __('Status') }}">
    <a class="app-doc-chip {{ $open ? 'is-active' : '' }}" href="{{ route('samples.index') }}">{{ __('Waiting for an answer') }}</a>
    <a class="app-doc-chip {{ $open ? '' : 'is-active' }}" href="{{ route('samples.index', ['show' => 'all']) }}">{{ __('All') }}</a>
</nav>
<x-section :title="__('Samples')" icon="gift" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Sample') }}</th><th scope="col">{{ __('What') }}</th><th scope="col">{{ __('Client') }}</th>@if($seesSuppliers)<th scope="col">{{ __('Factory') }}</th>@endif<th scope="col">{{ __('Requested') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($samples as $s)
                @php $st = $s->status(); @endphp
                <tr>
                    <td><a href="{{ route('samples.show', $s) }}" class="fw-semibold app-ltr">{{ $s->number }}</a></td>
                    <td><span class="d-inline-block text-truncate" style="max-inline-size: 22rem" dir="auto">{{ $s->description }}</span></td>
                    <td><bdi>{{ $s->client?->name ?? '—' }}</bdi></td>
                    @if($seesSuppliers)<td><bdi>{{ $s->supplier?->name ?? '—' }}</bdi></td>@endif
                    <td><span class="app-num">{{ $s->requested_on->format('Y-m-d') }}</span></td>
                    <td><x-status :tone="Sample::statusTone($st)">{{ Sample::statusLabel($st) }}</x-status></td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><x-empty icon="gift" :title="__('No samples here.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
<div class="mt-3">{{ $samples->links() }}</div>
@endsection
