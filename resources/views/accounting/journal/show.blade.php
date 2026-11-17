@extends('layouts.app')

@section('title', $entry->displayNumber())

@section('content')
@php
    use App\Services\Accounting\Decimal;
    use Illuminate\Support\HtmlString;

    $fmt = fn ($v) => Decimal::format($v);
    $fig = fn ($v) => Decimal::isZero($v) ? '' : new HtmlString('<span class="app-num app-fig">'.e(Decimal::format($v)).'</span>');
    $canReverse = $entry->isPosted() && ! $entry->reversal && ! $entry->reverses_entry_id && $entry->source_type !== 'payment';
    $balanced = Decimal::cmp($debit, $credit) === 0;

    $details = [
        __('Date') => new HtmlString('<span class="app-num">'.e($entry->entry_date->toDateString()).'</span>'),
        __('Event') => \App\Services\Accounting\PostingService::eventLabel($entry->event),
        __('Description') => $entry->description ?: null,
    ];
    if ($source) {
        $details[__('Source')] = $source[1] ? new HtmlString('<a href="'.e($source[1]).'">'.e($source[0]).'</a>') : $source[0];
    }
    if ($entry->reverses) {
        $details[__('Reverses')] = new HtmlString('<a href="'.e(route('accounting.journal.show', $entry->reverses)).'" class="app-ltr">'.e($entry->reverses->number).'</a>');
    }
    if ($entry->reversal) {
        $details[__('Reversed by')] = new HtmlString('<a href="'.e(route('accounting.journal.show', $entry->reversal)).'" class="app-ltr">'.e($entry->reversal->number).'</a>');
    }
    $details[__('Created by')] = new HtmlString(e($entry->creator?->name).' · <span class="app-num">'.e($entry->created_at?->toDateTimeString()).'</span>');
    if ($entry->isPosted()) {
        $details[__('Posted by')] = new HtmlString(e($entry->poster?->name).' · <span class="app-num">'.e($entry->posted_at?->toDateTimeString()).'</span>');
    }
@endphp
<x-page-header :title="new HtmlString('<bdi>'.e($entry->displayNumber()).'</bdi>')" :back="route('accounting.journal.index')" :back-label="__('Journal')">
    <x-slot:meta>
        @if($entry->isDraft())
            <x-status tone="due" icon="pencil-square">{{ __('Draft') }}</x-status>
        @else
            <x-status tone="done">{{ __('Posted') }}</x-status>
        @endif
        @if($entry->reversal)<x-status tone="skip" icon="x-circle">{{ __('Reversed') }}</x-status>@endif
    </x-slot:meta>
    <x-slot:actions>
        @if($entry->isDraft())
            @can('accounting.edit')
                <a href="{{ route('accounting.journal.edit', $entry) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
                <button type="button" class="btn btn-outline-danger" onclick="confirmDelete(@js(route('accounting.journal.destroy', $entry)), @js(__('Delete this draft entry?')))"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete') }}</button>
            @endcan
            @can('accounting.approve')
                <form method="POST" action="{{ route('accounting.journal.post', $entry) }}" onsubmit="return confirm(@js(__('Post this entry? A posted entry never changes; it can only be reversed.')));">
                    @csrf <button class="btn btn-primary"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Post') }}</button>
                </form>
            @endcan
        @elseif($canReverse)
            @can('accounting.approve')
                <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#reverse-form" aria-expanded="false" aria-controls="reverse-form"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reverse') }}</button>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

@can('accounting.approve')
    @if($canReverse)
        <form method="POST" action="{{ route('accounting.journal.reverse', $entry) }}" id="reverse-form" class="collapse mb-4">
            @csrf
            <x-section :title="__('Reverse')" icon="arrow-counterclockwise" :subtitle="__('A new posted entry with the debits and credits swapped.')">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label" for="reverse_on">{{ __('Reversal date') }}</label>
                        <input id="reverse_on" name="reverse_on" type="date" class="form-control" value="{{ now()->toDateString() }}">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="reason">{{ __('Reason') }}</label>
                        <input id="reason" name="reason" class="form-control" maxlength="500">
                    </div>
                    <div class="col-md-2"><button class="btn btn-warning w-100"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reverse') }}</button></div>
                </div>
            </x-section>
        </form>
    @endif
@endcan

<x-section :title="__('Entry')" icon="info-circle" class="mb-4">
    <x-detail-list :items="$details" :cols="3" />
</x-section>

<x-section :title="__('Lines')" icon="list-ul" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 app-ledger">
            <thead><tr>
                <th scope="col">{{ __('Account') }}</th><th scope="col">{{ __('Party') }}</th><th scope="col">{{ __('Memo') }}</th>
                <th scope="col" class="text-end">{{ __('Amount in currency') }}</th><th scope="col" class="text-end">{{ __('Rate') }}</th>
                <th scope="col" class="text-end">{{ __('Debit') }} <span class="app-unit">{{ $book }}</span></th><th scope="col" class="text-end">{{ __('Credit') }} <span class="app-unit">{{ $book }}</span></th>
            </tr></thead>
            <tbody>
            @foreach($entry->lines as $line)
                <tr>
                    <td>{{ $line->account?->label() }}</td>
                    <td>{{ $line->party ? ($canSee($line->party) ? $line->party->name : __('(hidden)')) : '—' }}</td>
                    <td class="small">{{ $line->memo }}</td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($line->amount_currency) }}</span><span class="app-unit">{{ $line->currency }}</span></td>
                    <td class="app-figcell"><span class="app-num text-muted">{{ rtrim(rtrim((string) $line->rate, '0'), '.') }}</span></td>
                    <td class="app-figcell">{{ $fig($line->debit) }}</td>
                    <td class="app-figcell">{{ $fig($line->credit) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th scope="row" colspan="5">
                        {{ __('Total') }}
                        @if($balanced)
                            <x-status tone="done" icon="check2-circle" class="ms-2">{{ __('Balanced') }}</x-status>
                        @else
                            <x-status tone="late" class="ms-2">{{ __('Not balanced') }}</x-status>
                        @endif
                    </th>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($debit) }}</span></td>
                    <td class="app-figcell"><span class="app-num app-fig">{{ $fmt($credit) }}</span></td>
                </tr>
            </tfoot>
        </table>
    </div>
</x-section>

@include('partials.attachments', ['attachable' => $entry, 'type' => 'journal-entries'])
@include('partials.delete-modal')
@endsection
