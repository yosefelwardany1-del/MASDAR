@extends('layouts.app')

@php use App\Services\Accounting\Decimal; $f = fn ($v) => Decimal::format((string) $v); @endphp

@section('title', $asset->code)

@section('content')
<x-page-header :title="$asset->code.' · '.$asset->name" :back="route('fixed-assets.index')" :back-label="__('Fixed assets')" :subtitle="$asset->location">
    @can('fixed_assets.edit')
        <x-slot:actions><a href="{{ route('fixed-assets.edit', $asset) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a></x-slot:actions>
    @endcan
</x-page-header>

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg-3"><x-kpi :label="__('Cost')" :value="$f($asset->cost)" icon="tag" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Per month')" :value="$f($asset->monthlyCharge())" icon="calendar-month" :hint="trans_choice('{1} over one month|[2,*] over :count months', $asset->useful_life_months, ['count' => $asset->useful_life_months])" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Depreciated')" :value="$f($asset->depreciated())" icon="graph-down" /></div>
    <div class="col-6 col-lg-3"><x-kpi :label="__('Book value')" :value="$f($asset->bookValue())" icon="wallet2" tone="gold" /></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <x-section :title="__('Depreciation posted')" icon="journal-check" flush>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Month') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Entry') }}</th></tr></thead>
                    <tbody>
                    @forelse($asset->depreciation as $d)
                        <tr><td><span class="app-num">{{ $d->month->format('Y-m') }}</span></td><td class="text-end"><span class="app-num">{{ $f($d->amount) }}</span></td>
                            <td>@can('accounting.view')<a href="{{ route('accounting.journal.show', $d->journal_entry_id) }}">{{ __('Open') }}</a>@endcan</td></tr>
                    @empty
                        <tr><td colspan="3" class="p-0"><div class="app-panel-empty">{{ __('Not depreciated yet.') }}</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </x-section>
    </div>
    <div class="col-lg-5">
        <x-section :title="__('Details')" icon="info-circle" class="mb-3">
            <x-detail-list :cols="1" :items="[
                __('Bought on') => $asset->acquired_on->format('Y-m-d'),
                __('Depreciated from') => $asset->depreciation_starts_on->format('Y-m'),
                __('Worth at the end') => $f($asset->residual_value),
                __('Asset account') => $asset->assetAccount?->label(),
                __('Accumulated depreciation account') => $asset->depreciationAccount?->label(),
                __('Depreciation expense account') => $asset->expenseAccount?->label(),
            ]" />
        </x-section>
        @if($asset->disposed_on)
            <div class="alert alert-secondary"><strong>{{ __('Gone :date', ['date' => $asset->disposed_on->format('Y-m-d')]) }}</strong> — {{ $asset->disposal_note }}</div>
        @elseif(Gate::allows('fixed_assets.edit'))
            <form method="POST" action="{{ route('fixed-assets.dispose', $asset) }}" class="app-card p-3 app-form" onsubmit="return confirm(@js(__('Mark this asset as gone?')));">
                @csrf
                <h2 class="h6">{{ __('Sold, scrapped or lost') }}</h2>
                {{-- Each message under its own field, with what was typed. --}}
                <div class="mb-2"><input type="date" name="disposed_on" value="{{ old('disposed_on', now()->toDateString()) }}" class="form-control @error('disposed_on') is-invalid @enderror" aria-label="{{ __('Date') }}">@error('disposed_on')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <div class="mb-2"><input name="disposal_note" value="{{ old('disposal_note') }}" class="form-control @error('disposal_note') is-invalid @enderror" placeholder="{{ __('How it left') }}" aria-label="{{ __('How it left') }}" dir="auto">@error('disposal_note')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                <button class="btn btn-outline-danger btn-sm">{{ __('Mark as gone') }}</button>
                <div class="form-text">{{ __('Depreciation stops. Record any sale price or write-off on the Journal screen.') }}</div>
            </form>
        @endif
    </div>
</div>

@include('partials.attachments', ['attachable' => $asset, 'type' => 'fixed-assets'])
@endsection
