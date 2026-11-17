@extends('layouts.app')

@section('title', __('Accounting periods'))

@section('content')
<x-page-header :title="__('Accounting periods')" :eyebrow="__('Accounting')"
               :subtitle="__('Nothing is posted with a date inside a closed period. Periods never overlap. Closing and reopening need a manager\'s approval.')" />
@include('accounting._nav', ['active' => 'periods'])

@can('accounting.edit')
    <form method="POST" action="{{ route('accounting.periods.store') }}" class="app-form mb-4">
        @csrf
        <x-section :title="__('Add period')" icon="calendar-plus">
            <div class="row g-3 align-items-start">
                <div class="col-md-4">
                    <label class="form-label" for="name">{{ __('Name') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="{{ __('e.g. 2026-11') }}" required maxlength="40">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label" for="starts_on">{{ __('From') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <input id="starts_on" name="starts_on" type="date" class="form-control @error('starts_on') is-invalid @enderror" value="{{ old('starts_on') }}" required>
                    @error('starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-sm-6 col-md-3">
                    <label class="form-label" for="ends_on">{{ __('To') }}<span class="app-req" aria-hidden="true">*</span></label>
                    <input id="ends_on" name="ends_on" type="date" class="form-control @error('ends_on') is-invalid @enderror" value="{{ old('ends_on') }}" required>
                    @error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <span class="form-label d-none d-md-block" aria-hidden="true">&nbsp;</span>
                    <button class="btn btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add period') }}</button>
                </div>
            </div>
        </x-section>
    </form>
@endcan

<x-section :title="__('Periods')" icon="calendar3" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Name') }}</th><th scope="col">{{ __('From') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Closed by') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th></tr></thead>
            <tbody>
            @forelse($periods as $period)
                <tr>
                    <td class="fw-semibold">{{ $period->name }}</td>
                    <td><span class="app-num">{{ $period->starts_on->toDateString() }}</span></td>
                    <td><span class="app-num">{{ $period->ends_on->toDateString() }}</span></td>
                    <td>@if($period->closed_at)<x-status tone="late" icon="lock">{{ __('Closed') }}</x-status>@else<x-status tone="done" icon="unlock">{{ __('Open') }}</x-status>@endif</td>
                    <td>@if($period->closed_at){{ $period->closer?->name }} · <span class="app-num">{{ $period->closed_at->toDateString() }}</span>@else<span class="text-muted">—</span>@endif</td>
                    <td class="text-end">
                        @can('accounting.approve')
                            @if($period->closed_at)
                                <form method="POST" action="{{ route('accounting.periods.reopen', $period) }}" onsubmit="return confirm(@js(__('Reopen this period? Entries can then be posted into it again.')));">
                                    @csrf <button class="btn btn-sm btn-light"><i class="bi bi-unlock" aria-hidden="true"></i>{{ __('Reopen') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('accounting.periods.close', $period) }}" onsubmit="return confirm(@js(__('Close this period? Nothing can then be posted into it.')));">
                                    @csrf <button class="btn btn-sm btn-warning"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Close') }}</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><x-empty icon="calendar3" :title="__('No periods yet.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
