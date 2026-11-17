@extends('layouts.app')

@section('title', $entry->displayNumber())

@section('content')
@php $fmt = fn ($v) => \App\Services\Accounting\Decimal::format($v); @endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">
        <span class="app-num">{{ $entry->displayNumber() }}</span>
        @if($entry->isDraft())
            <span class="app-pill app-pill--due ms-2">{{ __('Draft') }}</span>
        @else
            <span class="app-pill app-pill--done ms-2">{{ __('Posted') }}</span>
        @endif
        @if($entry->reversal)<span class="app-pill app-pill--skip ms-1">{{ __('Reversed') }}</span>@endif
    </h1>
    <div class="d-flex flex-wrap gap-2">
        @if($entry->isDraft())
            @can('accounting.edit')
                <a href="{{ route('accounting.journal.edit', $entry) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit') }}</a>
                <button type="button" class="btn btn-light text-danger" onclick="confirmDelete(@js(route('accounting.journal.destroy', $entry)), @js(__('Delete this draft entry?')))"><i class="bi bi-trash me-1"></i>{{ __('Delete') }}</button>
            @endcan
            @can('accounting.approve')
                <form method="POST" action="{{ route('accounting.journal.post', $entry) }}" onsubmit="return confirm(@js(__('Post this entry? A posted entry never changes; it can only be reversed.')));">
                    @csrf <button class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>{{ __('Post') }}</button>
                </form>
            @endcan
        @elseif(! $entry->reversal && ! $entry->reverses_entry_id && $entry->source_type !== 'payment')
            @can('accounting.approve')
                <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#reverse-form"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reverse') }}</button>
            @endcan
        @endif
    </div>
</div>

@can('accounting.approve')
    @if($entry->isPosted() && ! $entry->reversal && ! $entry->reverses_entry_id && $entry->source_type !== 'payment')
        <form method="POST" action="{{ route('accounting.journal.reverse', $entry) }}" id="reverse-form" class="collapse app-card p-3 mb-3">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="reverse_on">{{ __('Reversal date') }}</label>
                    <input id="reverse_on" name="reverse_on" type="date" class="form-control" value="{{ now()->toDateString() }}">
                </div>
                <div class="col-md-7">
                    <label class="form-label" for="reason">{{ __('Reason') }}</label>
                    <input id="reason" name="reason" class="form-control" maxlength="500">
                </div>
                <div class="col-md-2"><button class="btn btn-warning w-100">{{ __('Reverse') }}</button></div>
            </div>
            <div class="form-text">{{ __('A new posted entry with the debits and credits swapped.') }}</div>
        </form>
    @endif
@endcan

<div class="app-card p-4 mb-3">
    <dl class="row mb-0">
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Date') }}</dt><dd class="col-sm-9 app-num">{{ $entry->entry_date->toDateString() }}</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Event') }}</dt><dd class="col-sm-9">{{ \App\Services\Accounting\PostingService::eventLabel($entry->event) }}</dd>
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Description') }}</dt><dd class="col-sm-9">{{ $entry->description ?: '—' }}</dd>
        @if($source)
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Source') }}</dt>
            <dd class="col-sm-9">@if($source[1])<a href="{{ $source[1] }}">{{ $source[0] }}</a>@else{{ $source[0] }}@endif</dd>
        @endif
        @if($entry->reverses)
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Reverses') }}</dt><dd class="col-sm-9"><a href="{{ route('accounting.journal.show', $entry->reverses) }}" class="app-num">{{ $entry->reverses->number }}</a></dd>
        @endif
        @if($entry->reversal)
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Reversed by') }}</dt><dd class="col-sm-9"><a href="{{ route('accounting.journal.show', $entry->reversal) }}" class="app-num">{{ $entry->reversal->number }}</a></dd>
        @endif
        <dt class="col-sm-3 text-muted fw-normal">{{ __('Created by') }}</dt><dd class="col-sm-9">{{ $entry->creator?->name }} · {{ $entry->created_at?->toDateTimeString() }}</dd>
        @if($entry->isPosted())
            <dt class="col-sm-3 text-muted fw-normal">{{ __('Posted by') }}</dt><dd class="col-sm-9">{{ $entry->poster?->name }} · {{ $entry->posted_at?->toDateTimeString() }}</dd>
        @endif
    </dl>
</div>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('Account') }}</th><th>{{ __('Party') }}</th><th>{{ __('Memo') }}</th><th>{{ __('Currency') }}</th>
                <th class="text-end">{{ __('Amount in currency') }}</th><th class="text-end">{{ __('Rate') }}</th>
                <th class="text-end">{{ __('Debit') }}</th><th class="text-end">{{ __('Credit') }}</th>
            </tr></thead>
            <tbody>
            @foreach($entry->lines as $line)
                <tr>
                    <td>{{ $line->account?->label() }}</td>
                    <td>{{ $line->party ? ($canSee($line->party) ? $line->party->name : __('(hidden)')) : '—' }}</td>
                    <td>{{ $line->memo }}</td>
                    <td>{{ $line->currency }}</td>
                    <td class="app-num text-end">{{ $fmt($line->amount_currency) }}</td>
                    <td class="app-num text-end">{{ rtrim(rtrim((string) $line->rate, '0'), '.') }}</td>
                    <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($line->debit) ? '' : $fmt($line->debit) }}</td>
                    <td class="app-num text-end">{{ \App\Services\Accounting\Decimal::isZero($line->credit) ? '' : $fmt($line->credit) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr class="fw-semibold">
                    <td colspan="6">{{ __('Total') }}
                        @if(\App\Services\Accounting\Decimal::cmp($debit, $credit) !== 0)
                            <span class="app-pill app-pill--late ms-2">{{ __('Not balanced') }}</span>
                        @endif
                    </td>
                    <td class="app-num text-end">{{ $fmt($debit) }}</td>
                    <td class="app-num text-end">{{ $fmt($credit) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@include('partials.attachments', ['attachable' => $entry, 'type' => 'journal-entries'])
@include('partials.delete-modal')
@endsection
