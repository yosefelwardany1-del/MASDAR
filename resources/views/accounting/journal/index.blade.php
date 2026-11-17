@extends('layouts.app')

@section('title', __('Journal'))

@section('content')
@php
    $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v);
    $filtered = collect($filters)->filter(fn ($v) => $v !== null && $v !== '')->isNotEmpty();
@endphp
<x-page-header :title="__('Journal')" :eyebrow="__('Accounting')" :subtitle="__('Every journal entry, posted or draft. A posted entry never changes; it can only be reversed.')">
    @can('accounting.edit')
        <x-slot:actions>
            <a href="{{ route('accounting.journal.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New journal entry') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>
@include('accounting._nav', ['active' => 'journal'])

<form method="GET" class="app-filterbar" role="search" aria-label="{{ __('Journal') }}">
    <div class="app-filterbar__field app-filterbar__field--narrow">
        <label class="form-label" for="from">{{ __('From') }}</label>
        <input id="from" type="date" name="from" value="{{ $filters['from'] }}" class="form-control form-control-sm">
    </div>
    <div class="app-filterbar__field app-filterbar__field--narrow">
        <label class="form-label" for="to">{{ __('To') }}</label>
        <input id="to" type="date" name="to" value="{{ $filters['to'] }}" class="form-control form-control-sm">
    </div>
    <div class="app-filterbar__field">
        <label class="form-label" for="status">{{ __('Status') }}</label>
        <select id="status" name="status" class="form-select form-select-sm">
            <option value="">{{ __('All statuses') }}</option>
            <option value="draft" @selected($filters['status'] === 'draft')>{{ __('Draft') }}</option>
            <option value="posted" @selected($filters['status'] === 'posted')>{{ __('Posted') }}</option>
        </select>
    </div>
    <div class="app-filterbar__field">
        <label class="form-label" for="event">{{ __('Event') }}</label>
        <select id="event" name="event" class="form-select form-select-sm">
            <option value="">{{ __('All events') }}</option>
            <option value="manual" @selected($filters['event'] === 'manual')>{{ __('Manual entry') }}</option>
            @foreach($events as $key => $label)
                <option value="{{ $key }}" @selected($filters['event'] === $key)>{{ __($label) }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="account_id">{{ __('Account') }}</label>
        <select id="account_id" name="account_id" class="form-select form-select-sm">
            <option value="">{{ __('All accounts') }}</option>
            @foreach($accounts as $account)
                <option value="{{ $account->id }}" @selected($filters['account_id'] === $account->id)>{{ $account->label() }}</option>
            @endforeach
        </select>
    </div>
    <div class="app-filterbar__field app-filterbar__field--wide">
        <label class="form-label" for="search">{{ __('Search') }}</label>
        <input id="search" type="search" name="search" value="{{ $filters['search'] }}" class="form-control form-control-sm" placeholder="{{ __('Number, description, memo') }}">
    </div>
    <div class="app-filterbar__actions">
        <button class="btn btn-sm btn-primary"><i class="bi bi-search" aria-hidden="true"></i>{{ __('Search') }}</button>
        @if($filtered)<a href="{{ route('accounting.journal.index') }}" class="btn btn-sm btn-light">{{ __('Clear') }}</a>@endif
    </div>
</form>

<x-section :title="__('Entries')" icon="journal-text" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Event') }}</th><th scope="col">{{ __('Description') }}</th>
                <th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
            </tr></thead>
            <tbody>
            @forelse($entries as $entry)
                <tr>
                    <td class="text-nowrap"><a href="{{ route('accounting.journal.show', $entry) }}" class="fw-semibold"><bdi>{{ $entry->displayNumber() }}</bdi></a></td>
                    <td class="text-nowrap"><span class="app-num">{{ $entry->entry_date->toDateString() }}</span></td>
                    <td class="small">{{ \App\Services\Accounting\PostingService::eventLabel($entry->event) }}</td>
                    <td>{{ \Illuminate\Support\Str::limit($entry->description, 90) }}</td>
                    <td class="text-end"><span class="app-num app-fig">{{ $fmt($entry->lines_sum_debit) }}</span></td>
                    <td>
                        <div class="d-flex flex-wrap gap-1">
                            @if($entry->isDraft())
                                <x-status tone="due" icon="pencil-square">{{ __('Draft') }}</x-status>
                            @else
                                <x-status tone="done">{{ __('Posted') }}</x-status>
                            @endif
                            @if($entry->reversal)<x-status tone="skip" icon="x-circle">{{ __('Reversed') }}</x-status>@endif
                            @if($entry->reverses)<x-status tone="skip" icon="arrow-counterclockwise">{{ __('Reversal') }}</x-status>@endif
                        </div>
                    </td>
                    <td class="text-end"><a href="{{ route('accounting.journal.show', $entry) }}" class="btn btn-sm btn-light" title="{{ __('View') }}" aria-label="{{ __('View') }}: {{ $entry->displayNumber() }}"><i class="bi bi-eye" aria-hidden="true"></i></a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="p-0"><x-empty icon="journal-text" :title="__('No journal entries found.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($entries->hasPages())<div class="p-3 border-top">{{ $entries->links() }}</div>@endif
</x-section>
@endsection
