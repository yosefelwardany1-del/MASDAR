@extends('layouts.app')

@section('title', __('Leave & attendance'))

@section('content')
@php $fmtDays = fn ($d) => $d === null ? '—' : rtrim(rtrim((string) $d, '0'), '.'); @endphp
<x-page-header :title="__('Leave & attendance')" :subtitle="__('Requests and their decisions, what each employee has left, and the month\'s attendance.')">
    @if($pendingCount)
        <x-slot:meta><x-status tone="due" icon="hourglass-split">{{ trans_choice('{1} One request to decide|[2,*] :count requests to decide', $pendingCount, ['count' => $pendingCount]) }}</x-status></x-slot:meta>
    @endif
</x-page-header>

@include('hr._leave-nav', ['active' => $tab])

@if($tab === 'requests')
    <div class="row g-3">
        <div class="{{ Gate::allows('leave.edit') ? 'col-xl-8' : 'col-12' }}">
            <x-section :title="__('Requests')" icon="calendar-check" flush>
                <x-slot:actions>
                    <form method="GET" class="d-flex gap-2 align-items-center">
                        <input type="hidden" name="tab" value="requests">
                        <label class="visually-hidden" for="lv-status">{{ __('Status') }}</label>
                        <select name="status" id="lv-status" class="form-select form-select-sm" onchange="this.form.submit()">
                            @foreach(['all' => __('All'), 'pending' => __('Waiting for a decision'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'cancelled' => __('Cancelled')] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <noscript><button class="btn btn-sm btn-light">{{ __('Filter') }}</button></noscript>
                    </form>
                </x-slot:actions>
                @include('hr._requests', ['requests' => $requests, 'showEmployee' => true, 'selfService' => false])
            </x-section>
            <div class="mt-3">{{ $requests->links() }}</div>
        </div>
        @can('leave.edit')
            <div class="col-xl-4">
                <x-section :title="__('Record leave for an employee')" icon="plus-circle" id="new">
                    @include('hr._leave-form', ['action' => route('leave.store'), 'types' => $types, 'employees' => $employees])
                </x-section>
            </div>
        @endcan
    </div>
@else
    <x-section :title="__('Balances')" icon="pie-chart" :subtitle="__('Days left of each kind of leave in :year: the allowance less approved and waiting days.', ['year' => $year])" flush>
        <x-slot:actions>
            <a href="{{ route('leave.index', ['tab' => 'balances', 'year' => $year - 1]) }}" class="btn btn-sm btn-light"><span class="app-num">{{ $year - 1 }}</span></a>
            <a href="{{ route('leave.index', ['tab' => 'balances', 'year' => $year + 1]) }}" class="btn btn-sm btn-light"><span class="app-num">{{ $year + 1 }}</span></a>
        </x-slot:actions>
        @php $counted = collect($types)->filter(fn ($t) => $t['days'] !== null); @endphp
        <div class="table-responsive">
            <table class="table align-middle mb-0 app-hr-balancegrid">
                <thead>
                    <tr>
                        <th scope="col">{{ __('Employee') }}</th>
                        @foreach($counted as $code => $t)<th scope="col" class="text-end">{{ $t['label'] }}</th>@endforeach
                    </tr>
                </thead>
                <tbody>
                @forelse($employees as $e)
                    <tr>
                        <th scope="row"><a href="{{ route('employees.show', ['employee' => $e, 'year' => $year]) }}#leave">{{ $e->name }}</a></th>
                        @foreach($counted as $code => $t)
                            @php $b = $balances[$e->id][$code] ?? null; @endphp
                            <td class="text-end">
                                @if($b)
                                    <span class="app-num fw-semibold {{ bccomp($b['left'], '0', 2) <= 0 ? 'text-danger' : '' }}">{{ $fmtDays($b['left']) }}</span>
                                    <span class="app-unit">/ {{ $fmtDays($b['allowance']) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="{{ $counted->count() + 1 }}" class="p-0"><div class="app-panel-empty">{{ __('No employees on the staff.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <p class="app-note m-3"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('Days a year are set per kind of leave on Lists & options; an employee\'s own allowance for a year on their page.') }}</span></p>
    </x-section>
@endif
@endsection
