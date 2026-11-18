@extends('layouts.app')

@php
    use App\Models\ExpenseClaim;
    use App\Services\Accounting\Decimal;
    $editable = $claim->isEditable() && ($mine || Gate::allows('expense_claims.edit'));
    $status = $claim->status();
    $decisionLabels = ['submitted' => __('Submitted'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'returned' => __('Returned for changes')];
@endphp

@section('title', $claim->number)

@section('content')
<x-page-header :title="$claim->number.' · '.$claim->title" :subtitle="$claim->user?->name" :back="$mine ? route('my-expenses.index') : route('expense-claims.index')" :back-label="$mine ? __('My expenses') : __('Expense claims')">
    <x-slot:meta>@include('expense-claims._status', ['claim' => $claim, 'paidCount' => $paid ? 1 : 0])</x-slot:meta>
    <x-slot:actions>
        @if($editable && $mine)
            <form method="POST" action="{{ route('my-expenses.submit', $claim) }}">@csrf<button class="btn btn-primary" @disabled($claim->lines->isEmpty())><i class="bi bi-send" aria-hidden="true"></i>{{ __('Submit for approval') }}</button></form>
        @endif
        @if(! $mine && $status === 'approved' && ! $paid && Gate::allows('payments.edit'))
            <a href="{{ route('payments.create', ['kind' => 'claim_payment', 'claim' => $claim->id]) }}" class="btn btn-primary"><i class="bi bi-cash-coin" aria-hidden="true"></i>{{ __('Pay it back') }}</a>
        @endif
    </x-slot:actions>
</x-page-header>
@error('claim')<div class="alert alert-danger">{{ $message }}</div>@enderror

<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <x-section :title="__('Receipts')" icon="receipt" flush>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('What') }}</th><th scope="col">{{ __('For') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th>@if($editable)<th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif</tr></thead>
                    <tbody>
                    @forelse($claim->lines as $line)
                        <tr>
                            <td><span class="app-num">{{ $line->spent_on->format('Y-m-d') }}</span></td>
                            <td>{{ $categories[$line->category] ?? $line->category }}<div class="small text-muted" dir="auto">{{ $line->description }}</div></td>
                            <td class="small"><span class="app-ltr">{{ $line->proformaInvoice?->reference() ?? $line->container?->container_no ?? '—' }}</span></td>
                            <td class="text-end"><span class="app-num fw-semibold">{{ Decimal::format((string) $line->amount) }}</span></td>
                            @if($editable)
                                <td class="text-end"><form method="POST" action="{{ route('my-expenses.lines.destroy', [$claim, $line]) }}" onsubmit="return confirm(@js(__('Remove this receipt?')));">@csrf @method('DELETE')<button class="btn btn-sm btn-light" aria-label="{{ __('Remove') }}"><i class="bi bi-trash" aria-hidden="true"></i></button></form></td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="5" class="p-0"><div class="app-panel-empty"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('No receipts yet.') }}</div></td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr class="app-ledger__total"><th scope="row" colspan="3">{{ __('Total') }}</th><td class="text-end"><span class="app-num fw-semibold">{{ Decimal::format($total) }} {{ $claim->currency }}</span></td>@if($editable)<td></td>@endif</tr></tfoot>
                </table>
            </div>
            @if($editable)
                <form method="POST" action="{{ route('my-expenses.lines.store', $claim) }}" class="row g-2 align-items-end p-3 border-top app-form">
                    @csrf
                    <div class="col-6 col-md-3"><label class="form-label small" for="spent_on">{{ __('Date') }}</label><input id="spent_on" type="date" name="spent_on" value="{{ old('spent_on', now()->toDateString()) }}" class="form-control form-control-sm @error('spent_on') is-invalid @enderror" required></div>
                    <div class="col-6 col-md-3"><label class="form-label small" for="category">{{ __('What it was') }}</label><select id="category" name="category" class="form-select form-select-sm @error('category') is-invalid @enderror" required>@foreach($categories as $code => $label)<option value="{{ $code }}" @selected(old('category') === $code)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-6 col-md-2"><label class="form-label small" for="amount">{{ __('Amount') }}</label><input id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" class="form-control form-control-sm @error('amount') is-invalid @enderror" required></div>
                    <div class="col-6 col-md-4"><label class="form-label small" for="description">{{ __('Details') }}</label><input id="description" name="description" value="{{ old('description') }}" class="form-control form-control-sm @error('description') is-invalid @enderror" required dir="auto"></div>
                    <div class="col-md-4"><label class="form-label small" for="proforma_invoice_id">{{ __('For the PI (optional)') }}</label><select id="proforma_invoice_id" name="proforma_invoice_id" class="form-select form-select-sm"><option value="">—</option>@foreach($pis as $p)<option value="{{ $p->id }}">{{ $p->reference() }}</option>@endforeach</select></div>
                    <div class="col-md-4"><label class="form-label small" for="container_id">{{ __('For the container (optional)') }}</label><select id="container_id" name="container_id" class="form-select form-select-sm"><option value="">—</option>@foreach($containers as $c)<option value="{{ $c->id }}">{{ $c->container_no ?: '#'.$c->id }}</option>@endforeach</select></div>
                    <div class="col-md-4"><button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add the receipt') }}</button></div>
                    @foreach(['spent_on', 'category', 'amount', 'description'] as $field)@error($field)<div class="col-12 text-danger small">{{ $message }}</div>@enderror @endforeach
                </form>
            @endif
        </x-section>
    </div>
    <div class="col-lg-4">
        @if(! $mine && $status === 'submitted' && Gate::allows('expense_claims.approve') && (int) $claim->user_id !== (int) auth()->id())
            <x-section :title="__('Decide')" icon="check2-square" class="mb-3">
                <form method="POST" action="{{ route('expense-claims.decide', $claim) }}" class="app-form">
                    @csrf
                    <label class="form-label" for="note">{{ __('Note (needed to reject or return)') }}</label>
                    <textarea id="note" name="note" rows="2" class="form-control mb-2 @error('note') is-invalid @enderror" dir="auto"></textarea>
                    @error('note')<div class="invalid-feedback d-block mb-2">{{ $message }}</div>@enderror
                    <div class="d-flex gap-2 flex-wrap">
                        <button name="decision" value="approved" class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Approve') }}</button>
                        <button name="decision" value="returned" class="btn btn-light">{{ __('Return for changes') }}</button>
                        <button name="decision" value="rejected" class="btn btn-outline-danger">{{ __('Reject') }}</button>
                    </div>
                </form>
            </x-section>
        @endif
        <x-section :title="__('History')" icon="clock-history" flush>
            <ol class="list-unstyled mb-0">
                @forelse($claim->decisions->reverse() as $d)
                    <li class="px-3 py-2 border-bottom">
                        <strong>{{ $decisionLabels[$d->decision] ?? $d->decision }}</strong>
                        <span class="small text-muted"> · {{ $d->user?->name }} · <span class="app-num">{{ $d->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span></span>
                        @if($d->note)<div class="small" dir="auto">{{ $d->note }}</div>@endif
                    </li>
                @empty
                    <li class="px-3 py-2 text-muted">{{ __('Not submitted yet.') }}</li>
                @endforelse
                @if($paid)
                    <li class="px-3 py-2"><strong>{{ __('Paid back') }}</strong> · <a href="{{ route('payments.show', $paid) }}" class="app-ltr">{{ $paid->number }}</a> <span class="small text-muted">{{ $paid->paid_on->format('Y-m-d') }}</span></li>
                @endif
            </ol>
        </x-section>
    </div>
</div>

@include('partials.attachments', ['attachable' => $claim, 'type' => 'expense-claims'])
@endsection
