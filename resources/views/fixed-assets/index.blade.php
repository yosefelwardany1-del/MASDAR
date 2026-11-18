@extends('layouts.app')

@section('title', __('Fixed assets'))

@php use App\Services\Accounting\Decimal; $f = fn ($v) => Decimal::format((string) $v); @endphp

@section('content')
<x-page-header :title="__('Fixed assets')" :eyebrow="__('Finance')" :subtitle="__('Office and warehouse equipment, depreciated straight-line each month to its residual value.')">
    @can('fixed_assets.edit')
        <x-slot:actions><a href="{{ route('fixed-assets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add an asset') }}</a></x-slot:actions>
    @endcan
</x-page-header>
@error('month')<div class="alert alert-danger">{{ $message }}</div>@enderror

@if(Gate::allows('fixed_assets.edit') && Gate::allows('accounting.edit'))
    <x-section :title="__('Depreciate a month')" icon="calendar-month" class="mb-4">
        <form method="GET" class="d-flex gap-2 align-items-end flex-wrap mb-3">
            <div><label class="form-label" for="month">{{ __('Month') }}</label><input id="month" type="month" name="month" value="{{ $month }}" class="form-control"></div>
            <button class="btn btn-light"><i class="bi bi-eye" aria-hidden="true"></i>{{ __('Preview') }}</button>
        </form>
        @if($charges->isEmpty())
            <p class="text-muted mb-0">{{ __('Nothing to depreciate for :month: every asset is done for it, not started or fully depreciated.', ['month' => $month]) }}</p>
        @else
            <ul class="list-unstyled small mb-3">@foreach($charges as [$asset, $amount])<li><span class="app-ltr">{{ $asset->code }}</span> {{ $asset->name }}: <span class="app-num">{{ $f($amount) }}</span></li>@endforeach</ul>
            <form method="POST" action="{{ route('fixed-assets.depreciate') }}" onsubmit="return confirm(@js(__('Post the depreciation for :month?', ['month' => $month])));">@csrf<input type="hidden" name="month" value="{{ $month }}"><button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Post :amount for :month', ['amount' => $f(Decimal::sum($charges->map(fn ($c) => $c[1]))), 'month' => $month]) }}</button></form>
        @endif
    </x-section>
@endif

<x-section :title="__('Register')" icon="pc-display" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr><th scope="col">{{ __('Code') }}</th><th scope="col">{{ __('Asset') }}</th><th scope="col">{{ __('Bought') }}</th><th scope="col" class="text-end">{{ __('Cost') }}</th><th scope="col" class="text-end">{{ __('Depreciated') }}</th><th scope="col" class="text-end">{{ __('Book value') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($assets as $a)
                <tr class="{{ $a->disposed_on ? 'text-muted' : '' }}">
                    <td><a href="{{ route('fixed-assets.show', $a) }}" class="app-ltr fw-semibold">{{ $a->code }}</a></td>
                    <td>{{ $a->name }}@if($a->location)<div class="small text-muted">{{ $a->location }}</div>@endif</td>
                    <td><span class="app-num">{{ $a->acquired_on->format('Y-m-d') }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $f($a->cost) }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $f($a->depreciated()) }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig fw-semibold">{{ $f($a->bookValue()) }}</span></td>
                    <td>@if($a->disposed_on)<x-status tone="skip">{{ __('Gone :date', ['date' => $a->disposed_on->format('Y-m-d')]) }}</x-status>@else<x-status tone="done">{{ __('In use') }}</x-status>@endif</td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-0"><x-empty icon="pc-display" :title="__('No fixed assets yet.')" :text="__('Add computers, furniture, a vehicle — anything bought to keep and use for years.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
