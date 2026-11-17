@extends('layouts.app')

@section('title', __('Journal'))

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Accounting') }}</h1>
    @can('accounting.edit')
        <a href="{{ route('accounting.journal.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New journal entry') }}</a>
    @endcan
</div>
@include('accounting._nav', ['active' => 'journal'])

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-6 col-md-2">
        <label class="form-label small" for="from">{{ __('From') }}</label>
        <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="form-control">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small" for="to">{{ __('To') }}</label>
        <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="form-control">
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small" for="status">{{ __('Status') }}</label>
        <select id="status" name="status" class="form-select">
            <option value="">{{ __('All statuses') }}</option>
            <option value="draft" @selected($filters['status'] === 'draft')>{{ __('Draft') }}</option>
            <option value="posted" @selected($filters['status'] === 'posted')>{{ __('Posted') }}</option>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small" for="event">{{ __('Event') }}</label>
        <select id="event" name="event" class="form-select">
            <option value="">{{ __('All events') }}</option>
            <option value="manual" @selected($filters['event'] === 'manual')>{{ __('Manual entry') }}</option>
            @foreach($events as $key => $label)
                <option value="{{ $key }}" @selected($filters['event'] === $key)>{{ __($label) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label small" for="account_id">{{ __('Account') }}</label>
        <select id="account_id" name="account_id" class="form-select">
            <option value="">{{ __('All accounts') }}</option>
            @foreach($accounts as $account)
                <option value="{{ $account->id }}" @selected($filters['account_id'] === $account->id)>{{ $account->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-12 col-md-2">
        <label class="form-label small" for="search">{{ __('Search') }}</label>
        <div class="input-group">
            <input id="search" type="search" name="search" value="{{ $filters['search'] }}" class="form-control" placeholder="{{ __('Number, description, memo') }}">
            <button class="btn btn-light"><i class="bi bi-search"></i></button>
        </div>
    </div>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Number') }}</th><th>{{ __('Date') }}</th><th>{{ __('Event') }}</th><th>{{ __('Description') }}</th>
                <th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td class="app-num text-nowrap">{{ $entry->displayNumber() }}</td>
                    <td class="app-num text-nowrap">{{ $entry->entry_date->toDateString() }}</td>
                    <td>{{ \App\Services\Accounting\PostingService::eventLabel($entry->event) }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($entry->description, 90) }}</td>
                    <td class="app-num text-end">{{ $fmt($entry->lines_sum_debit) }}</td>
                    <td class="text-nowrap">
                        @if($entry->isDraft())
                            <span class="app-pill app-pill--due">{{ __('Draft') }}</span>
                        @else
                            <span class="app-pill app-pill--done">{{ __('Posted') }}</span>
                        @endif
                        @if($entry->reversal)<span class="app-pill app-pill--skip">{{ __('Reversed') }}</span>@endif
                        @if($entry->reverses)<span class="app-pill app-pill--skip">{{ __('Reversal') }}</span>@endif
                    </td>
                    <td class="text-end"><a href="{{ route('accounting.journal.show', $entry) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="app-empty">{{ __('No journal entries found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($entries->hasPages())<div class="p-3">{{ $entries->links() }}</div>@endif
</div>
@endsection
