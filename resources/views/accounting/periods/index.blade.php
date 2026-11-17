@extends('layouts.app')

@section('title', __('Accounting periods'))

@section('content')
<h1 class="h4 mb-3">{{ __('Accounting') }}</h1>
@include('accounting._nav', ['active' => 'periods'])

<p class="text-muted small">{{ __('Nothing is posted with a date inside a closed period. Periods never overlap. Closing and reopening need a manager\'s approval.') }}</p>

@can('accounting.edit')
    <form method="POST" action="{{ route('accounting.periods.store') }}" class="app-card p-3 mb-3">
        @csrf
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="name">{{ __('Name') }}</label>
                <input id="name" name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" placeholder="{{ __('e.g. 2026-11') }}" required maxlength="40">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="starts_on">{{ __('From') }}</label>
                <input id="starts_on" name="starts_on" type="date" class="form-control @error('starts_on') is-invalid @enderror" value="{{ old('starts_on') }}" required>
                @error('starts_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ends_on">{{ __('To') }}</label>
                <input id="ends_on" name="ends_on" type="date" class="form-control @error('ends_on') is-invalid @enderror" value="{{ old('ends_on') }}" required>
                @error('ends_on')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100"><i class="bi bi-plus-lg me-1"></i>{{ __('Add period') }}</button>
            </div>
        </div>
    </form>
@endcan

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Status') }}</th><th>{{ __('Closed by') }}</th><th></th></tr></thead>
            <tbody>
            @forelse($periods as $period)
                <tr>
                    <td>{{ $period->name }}</td>
                    <td class="app-num">{{ $period->starts_on->toDateString() }}</td>
                    <td class="app-num">{{ $period->ends_on->toDateString() }}</td>
                    <td>@if($period->closed_at)<span class="app-pill app-pill--late">{{ __('Closed') }}</span>@else<span class="app-pill app-pill--done">{{ __('Open') }}</span>@endif</td>
                    <td>{{ $period->closed_at ? ($period->closer?->name.' · '.$period->closed_at->toDateString()) : '—' }}</td>
                    <td class="text-end">
                        @can('accounting.approve')
                            @if($period->closed_at)
                                <form method="POST" action="{{ route('accounting.periods.reopen', $period) }}" onsubmit="return confirm(@js(__('Reopen this period? Entries can then be posted into it again.')));">
                                    @csrf <button class="btn btn-sm btn-light">{{ __('Reopen') }}</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('accounting.periods.close', $period) }}" onsubmit="return confirm(@js(__('Close this period? Nothing can then be posted into it.')));">
                                    @csrf <button class="btn btn-sm btn-warning">{{ __('Close') }}</button>
                                </form>
                            @endif
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="app-empty">{{ __('No periods yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
