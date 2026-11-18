@extends('layouts.app')

@section('title', __('Supplier evaluation'))

@section('content')
@php
    $fmt = fn ($n) => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.');
@endphp

<x-page-header :title="__('Supplier evaluation')" :subtitle="__('How each supplier keeps its promises, over its orders that are not cancelled.')"
               :back="route('supplier-orders.index')" :back-label="__('Purchase orders')" />

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-end app-fu-filters" aria-label="{{ __('Filter') }}">
    <div>
        <label class="form-label" for="from">{{ __('Confirmed from') }}</label>
        <input id="from" type="date" name="from" value="{{ $from }}" class="form-control">
    </div>
    <div>
        <label class="form-label" for="to">{{ __('to') }}</label>
        <input id="to" type="date" name="to" value="{{ $to }}" class="form-control @error('to') is-invalid @enderror" @error('to') aria-describedby="to-error" @enderror>
    </div>
    <button class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Show') }}</button>
    @if($from || $to)<a href="{{ route('supplier-orders.evaluation') }}" class="btn btn-link btn-sm">{{ __('Clear filters') }}</a>@endif
    @error('to')<div class="text-danger small w-100" id="to-error" role="alert">{{ $message }}</div>@enderror
</form>

<x-section :title="__('Suppliers')" icon="buildings" flush>
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Factory / supplier') }}</th>
                <th class="text-end">{{ __('Orders') }}</th>
                <th>{{ __('On time') }}</th>
                <th class="text-end">{{ __('Average slippage (days)') }}</th>
                <th class="text-end">{{ __('Delivery date changes') }}</th>
                <th class="text-end">{{ __('Inspection failures') }}</th>
                <th class="text-end">{{ __('Sample reopenings') }}</th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                @php $pct = $row->on_time_percent; @endphp
                <tr>
                    <td class="fw-semibold">{{ $seeSuppliers ? $row->supplier_name : '—' }}</td>
                    <td class="app-num text-end">{{ $row->orders }}</td>
                    <td>
                        @if($pct === null)
                            <span class="text-muted">—</span> <span class="app-fu-sub">{{ __('Nothing to judge yet') }}</span>
                        @else
                            <div class="app-fu-ontime">
                                <div class="d-flex align-items-baseline gap-2">
                                    <span class="app-num fw-semibold">{{ $fmt($pct) }}%</span>
                                    <span class="text-muted small app-num">({{ $row->on_time }}/{{ $row->judged }})</span>
                                </div>
                                <x-progress :value="$pct" :max="100" thin />
                            </div>
                        @endif
                    </td>
                    <td class="app-num text-end">
                        @if($row->avg_slippage_days === null)
                            —
                        @else
                            {{ $fmt($row->avg_slippage_days) }}
                        @endif
                    </td>
                    <td class="app-num text-end">{{ $row->date_changes }}</td>
                    <td class="app-num text-end {{ $row->inspection_failures > 0 ? 'fw-semibold' : '' }}">{{ $row->inspection_failures }}</td>
                    <td class="app-num text-end">{{ $row->sample_reopenings }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-0">
                    <x-empty icon="bar-chart" :title="__('No supplier orders in this period.')" :text="__('Try a wider date range.')" />
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

<x-section :title="__('How these figures are worked out')" icon="info-circle" class="mt-3">
    <ul class="app-fu-explain">
        <li>
            <div class="app-fu-explain__term">{{ __('On time') }}</div>
            <p class="app-fu-explain__text">{{ __('On time: the order was fully received on or before the first delivery date the supplier promised. Only orders that are fully received, or whose first promised date has passed, are counted.') }}</p>
        </li>
        <li>
            <div class="app-fu-explain__term">{{ __('Average slippage (days)') }}</div>
            <p class="app-fu-explain__text">{{ __('Average slippage: days after the first promised date that the goods came in (still waiting: until today); early counts as zero.') }}</p>
        </li>
        <li>
            <div class="app-fu-explain__term">{{ __('Sample reopenings') }}</div>
            <p class="app-fu-explain__text">{{ __('Sample reopenings: how often the initial or final sample step was opened again.') }}</p>
        </li>
    </ul>
</x-section>
@endsection
