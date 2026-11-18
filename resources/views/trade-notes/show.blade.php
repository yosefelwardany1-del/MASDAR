@extends('layouts.app')

@php
    use App\Models\TradeNote;
    use App\Services\Accounting\Decimal;
    $kind = $note->kind;
    $title = ($note->isCredit() ? __('Credit note') : __('Debit note')).' '.($note->isDraft() ? __('(draft)') : $note->number);
    $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
@endphp

@section('title', $title)

@section('content')
<x-page-header :title="$title" :subtitle="$note->party?->name" :back="route('trade-notes.index', ['kind' => $kind])" :back-label="__('Claims & notes')">
    <x-slot:meta><x-status :tone="TradeNote::statusTone($note->status)">{{ TradeNote::statusLabel($note->status) }}</x-status></x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('trade-notes.print', $note) }}" target="_blank" class="btn btn-light"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</a>
        @if(! $note->isDraft() && Route::has('documents.send.create'))
            <a href="{{ route('documents.send.create', ['type' => $note->isCredit() ? 'credit-note' : 'debit-note', 'id' => $note->id]) }}" class="btn btn-light"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send') }}</a>
        @endif
        @if($note->isDraft())
            @can('claims.approve')
                <form method="POST" action="{{ route('trade-notes.issue', $note) }}" class="d-flex gap-1" onsubmit="return confirm(@js(__('Issue this note? It never changes after.')));">
                    @csrf
                    <input type="date" name="issued_on" value="{{ now()->toDateString() }}" class="form-control" aria-label="{{ __('Date of the note') }}" style="max-inline-size: 11rem">
                    <button class="btn btn-primary"><i class="bi bi-patch-check" aria-hidden="true"></i>{{ __('Issue') }}</button>
                </form>
            @endcan
        @endif
    </x-slot:actions>
</x-page-header>

@error('note')<div class="alert alert-danger">{{ $message }}</div>@enderror
@if($note->isDraft() && ! Gate::allows('claims.approve'))
    <p class="app-note mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ __('A manager issues the note.') }}</span></p>
@endif

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('The note')" icon="arrow-left-right">
            @if($editable)
                <form method="POST" action="{{ route('trade-notes.update', $note) }}" class="app-form">
                    @csrf @method('PUT')
                    @include('trade-notes._header-form', ['preset' => []])
                    <div class="mt-3"><button class="btn btn-light"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button></div>
                </form>
            @else
                <x-detail-list :cols="2" :items="array_filter([
                    ($note->isCredit() ? __('Client') : __('Factory')) => $note->party?->name,
                    __('PI') => $note->proformaInvoice?->reference(),
                    __('Purchase order') => $note->supplierOrder?->number,
                    __('Claim') => $note->claim ? new \Illuminate\Support\HtmlString('<a class=\'app-ltr\' href=\''.e(route('claims.show', $note->claim)).'\'>'.e($note->claim->number).'</a>') : null,
                    __('Date') => $note->issued_on?->format('Y-m-d'),
                    __('Issued by') => $note->issuer?->name,
                ], fn ($v) => $v !== null)" />
                <p class="mt-3 mb-0" style="white-space: pre-line" dir="auto">{{ $note->reason }}</p>
                @if($note->status === 'void')
                    <div class="alert alert-secondary mt-3 mb-0"><strong>{{ __('Void') }}:</strong> {{ $note->void_reason }}</div>
                @endif
            @endif
        </x-section>
    </div>
    <div class="col-lg-4">
        <div class="d-flex flex-column gap-3 h-100">
            <div class="flex-fill"><x-kpi :label="__('Total')" :value="Decimal::format($total).' '.$note->currency" icon="cash-stack" tone="gold" /></div>
            @if($note->status === 'issued')
                <div class="flex-fill"><x-kpi :label="__('In the books')" :value="$posted ? __('Posted') : __('Not posted yet')" icon="journal-check" :tone="$posted ? 'done' : 'due'"
                    :href="! $posted && Gate::allows('accounting.edit') ? route('accounting.to-post.index') : null" /></div>
            @endif
        </div>
    </div>
</div>

<x-section :title="__('Lines')" icon="list-ul" flush>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th scope="col">{{ __('No.') }}</th><th scope="col">{{ __('Description') }}</th>
                <th scope="col" class="text-end">{{ __('Quantity') }}</th><th scope="col" class="text-end">{{ __('Unit price') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th>
                @if($editable)<th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif
            </tr></thead>
            <tbody>
            @forelse($note->lines as $line)
                <tr>
                    <td><span class="app-num">{{ $line->line_no }}</span></td>
                    <td style="white-space: pre-line" dir="auto">{{ $line->description }}</td>
                    <td class="text-end"><span class="app-num">{{ $plain($line->quantity) }}</span></td>
                    <td class="text-end"><span class="app-num">{{ Decimal::format((string) $line->unit_price) }}</span></td>
                    <td class="text-end"><span class="app-num fw-semibold">{{ Decimal::format((string) $line->amount) }}</span></td>
                    @if($editable)
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light" data-toggle-row="nl-{{ $line->id }}" aria-label="{{ __('Edit') }}: {{ $line->line_no }}"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                            <form method="POST" action="{{ route('trade-notes.lines.destroy', [$note, $line]) }}" class="d-inline" onsubmit="return confirm(@js(__('Remove this line?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-light" aria-label="{{ __('Remove') }}: {{ $line->line_no }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form>
                        </td>
                    @endif
                </tr>
                @if($editable)
                    <tr id="nl-{{ $line->id }}" class="d-none">
                        <td colspan="6">
                            <form method="POST" action="{{ route('trade-notes.lines.update', [$note, $line]) }}" class="row g-2 align-items-end">
                                @csrf @method('PUT')
                                <div class="col-md-6"><label class="form-label small" for="d{{ $line->id }}">{{ __('Description') }}</label><input id="d{{ $line->id }}" name="description" value="{{ $line->description }}" class="form-control form-control-sm" dir="auto"></div>
                                <div class="col-md-2"><label class="form-label small" for="q{{ $line->id }}">{{ __('Quantity') }}</label><input id="q{{ $line->id }}" name="quantity" value="{{ $plain($line->quantity) }}" inputmode="decimal" class="form-control form-control-sm"></div>
                                <div class="col-md-2"><label class="form-label small" for="p{{ $line->id }}">{{ __('Unit price') }}</label><input id="p{{ $line->id }}" name="unit_price" value="{{ $plain($line->unit_price) }}" inputmode="decimal" class="form-control form-control-sm"></div>
                                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">{{ __('Save') }}</button></div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="6" class="p-0"><div class="app-panel-empty"><i class="bi bi-list-ul" aria-hidden="true"></i>{{ __('No lines yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($editable)
        <form method="POST" action="{{ route('trade-notes.lines.store', $note) }}" class="row g-2 align-items-end p-3 border-top">
            @csrf
            <div class="col-md-6"><label class="form-label small" for="nd">{{ __('Description') }}</label><input id="nd" name="description" class="form-control form-control-sm @error('description') is-invalid @enderror" dir="auto" required></div>
            <div class="col-md-2"><label class="form-label small" for="nq">{{ __('Quantity') }}</label><input id="nq" name="quantity" value="1" inputmode="decimal" class="form-control form-control-sm @error('quantity') is-invalid @enderror"></div>
            <div class="col-md-2"><label class="form-label small" for="np">{{ __('Unit price') }}</label><input id="np" name="unit_price" inputmode="decimal" class="form-control form-control-sm @error('unit_price') is-invalid @enderror" required></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add the line') }}</button></div>
            @foreach(['description', 'quantity', 'unit_price'] as $field)@error($field)<div class="col-12 text-danger small">{{ $message }}</div>@enderror @endforeach
        </form>
    @endif
</x-section>

@if($note->status === 'issued')
    @can('claims.approve')
        <form method="POST" action="{{ route('trade-notes.void', $note) }}" class="app-card p-3 mt-3 app-form" onsubmit="return confirm(@js(__('Void this note?')));">
            @csrf
            <label class="form-label" for="void_reason">{{ __('Void the note') }}</label>
            <div class="d-flex gap-2 flex-wrap">
                <input id="void_reason" name="void_reason" class="form-control" style="flex: 1 1 18rem" placeholder="{{ __('Why it is void') }}" required dir="auto">
                <button class="btn btn-outline-danger"><i class="bi bi-x-octagon" aria-hidden="true"></i>{{ __('Void') }}</button>
            </div>
            @if($posted)<div class="form-text">{{ __('It is posted: reverse its journal entry on the Accounting screen first.') }}</div>@endif
        </form>
    @endcan
@endif

@if($editable)
    <form method="POST" action="{{ route('trade-notes.destroy', $note) }}" class="mt-3" onsubmit="return confirm(@js(__('Delete this draft?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash" aria-hidden="true"></i>{{ __('Delete the draft') }}</button></form>
@endif

@include('partials.attachments', ['attachable' => $note, 'type' => 'trade-notes'])
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-toggle-row]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.toggleRow)?.classList.toggle('d-none')));
</script>
@endpush
