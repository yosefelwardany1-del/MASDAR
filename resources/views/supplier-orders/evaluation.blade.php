@extends('layouts.app')

@section('title', __('Supplier evaluation'))

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
    <h1 class="h4 mb-0">{{ __('Supplier evaluation') }}</h1>
    <a href="{{ route('supplier-orders.index') }}" class="btn btn-light"><i class="bi bi-kanban me-1"></i>{{ __('Order follow-up') }}</a>
</div>
<p class="text-muted small mb-3">{{ __('How each supplier keeps its promises, over its orders that are not cancelled.') }}</p>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-end">
    <div>
        <label class="form-label small mb-1" for="from">{{ __('Confirmed from') }}</label>
        <input id="from" type="date" name="from" value="{{ $from }}" class="form-control">
    </div>
    <div>
        <label class="form-label small mb-1" for="to">{{ __('to') }}</label>
        <input id="to" type="date" name="to" value="{{ $to }}" class="form-control @error('to') is-invalid @enderror">
    </div>
    <button class="btn btn-light">{{ __('Show') }}</button>
</form>
@error('to')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Factory / supplier') }}</th>
                <th class="text-end">{{ __('Orders') }}</th>
                <th class="text-end">{{ __('On time') }}</th>
                <th class="text-end">{{ __('Average slippage (days)') }}</th>
                <th class="text-end">{{ __('Delivery date changes') }}</th>
                <th class="text-end">{{ __('Inspection failures') }}</th>
                <th class="text-end">{{ __('Sample reopenings') }}</th>
            </tr></thead>
            <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $seeSuppliers ? $row->supplier_name : '—' }}</td>
                    <td class="app-num text-end">{{ $row->orders }}</td>
                    <td class="text-end">
                        @if($row->on_time_percent === null)
                            <span class="text-muted">—</span>
                        @else
                            <span class="app-num fw-semibold">{{ rtrim(rtrim(number_format($row->on_time_percent, 1, '.', ''), '0'), '.') }}%</span>
                            <span class="text-muted small app-num">({{ $row->on_time }}/{{ $row->judged }})</span>
                        @endif
                    </td>
                    <td class="app-num text-end">{{ $row->avg_slippage_days === null ? '—' : rtrim(rtrim(number_format($row->avg_slippage_days, 1, '.', ''), '0'), '.') }}</td>
                    <td class="app-num text-end">{{ $row->date_changes }}</td>
                    <td class="app-num text-end">{{ $row->inspection_failures }}</td>
                    <td class="app-num text-end">{{ $row->sample_reopenings }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="app-empty">{{ __('No supplier orders in this period.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="text-muted small mt-3">
    <p class="mb-1">{{ __('On time: the order was fully received on or before the first delivery date the supplier promised. Only orders that are fully received, or whose first promised date has passed, are counted.') }}</p>
    <p class="mb-1">{{ __('Average slippage: days after the first promised date that the goods came in (still waiting: until today); early counts as zero.') }}</p>
    <p class="mb-0">{{ __('Sample reopenings: how often the initial or final sample step was opened again.') }}</p>
</div>
@endsection
