@extends('layouts.app')

@php use App\Models\Sample; $st = $sample->status(); $sent = $sample->shipment(); @endphp

@section('title', $sample->number)

@section('content')
<x-page-header :title="$sample->number" :subtitle="$sample->client?->name" :back="route('samples.index')" :back-label="__('Samples')">
    <x-slot:meta><x-status :tone="Sample::statusTone($st)">{{ Sample::statusLabel($st) }}</x-status></x-slot:meta>
</x-page-header>
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <x-section :title="__('The sample')" icon="gift">
            <p style="white-space: pre-line" dir="auto">{{ $sample->description }}</p>
            <x-detail-list :cols="2" :items="array_filter([
                __('Client') => $sample->client?->name,
                __('Factory') => $seesSuppliers ? $sample->supplier?->name : null,
                __('PI') => $sample->proformaInvoice?->reference(),
                __('Product') => $sample->product?->name,
                __('Requested on') => $sample->requested_on->format('Y-m-d'),
                __('Needed by') => $sample->needed_by?->format('Y-m-d'),
                __('Courier') => $sent ? trim($sent->courier.' '.$sent->tracking_no) : null,
            ], fn ($v) => $v !== null)" />
        </x-section>
    </div>
    <div class="col-lg-5">
        @can('samples.edit')
            <x-section :title="__('What happened')" icon="signpost-split" class="mb-3">
                <form method="POST" action="{{ route('samples.record', $sample) }}" class="app-form">
                    @csrf
                    <select name="status" class="form-select mb-2" aria-label="{{ __('Status') }}" required>
                        @foreach(array_diff(Sample::STATUSES, ['requested']) as $s)<option value="{{ $s }}">{{ Sample::statusLabel($s) }}</option>@endforeach
                    </select>
                    <div class="row g-2 mb-2">
                        <div class="col-6"><input type="date" name="happened_on" value="{{ now()->toDateString() }}" class="form-control" aria-label="{{ __('Date') }}"></div>
                        <div class="col-6"><input name="courier" class="form-control @error('courier') is-invalid @enderror" placeholder="{{ __('Courier (when sent)') }}" aria-label="{{ __('Courier') }}"></div>
                        <div class="col-12"><input name="tracking_no" class="form-control" placeholder="{{ __('Tracking number') }}" aria-label="{{ __('Tracking number') }}" dir="ltr"></div>
                        <div class="col-12"><input name="note" class="form-control" placeholder="{{ __('Note') }}" aria-label="{{ __('Note') }}" dir="auto"></div>
                    </div>
                    @error('courier')<div class="text-danger small mb-2">{{ $message }}</div>@enderror
                    <button class="btn btn-primary w-100">{{ __('Save') }}</button>
                </form>
            </x-section>
        @endcan
        <x-section :title="__('History')" icon="clock-history" flush>
            <ol class="list-unstyled mb-0">
                @foreach($sample->events->reverse() as $e)
                    <li class="px-3 py-2 border-bottom"><x-status :tone="Sample::statusTone($e->status)">{{ Sample::statusLabel($e->status) }}</x-status>
                        <span class="small text-muted ms-1"><span class="app-num">{{ $e->happened_on->format('Y-m-d') }}</span> · {{ $e->user?->name }}</span>
                        @if($e->courier || $e->tracking_no)<div class="small app-ltr">{{ $e->courier }} {{ $e->tracking_no }}</div>@endif
                        @if($e->note)<div class="small" dir="auto">{{ $e->note }}</div>@endif</li>
                @endforeach
            </ol>
        </x-section>
    </div>
</div>
@include('partials.attachments', ['attachable' => $sample, 'type' => 'samples'])
@endsection
