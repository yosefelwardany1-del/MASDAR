@extends('layouts.app')

@section('title', __('Bank reconciliation'))

@php use App\Services\Accounting\Decimal; @endphp

@section('content')
<x-page-header :title="__('Bank reconciliation')" :eyebrow="__('Finance')" :subtitle="__('Import each bank statement and match its lines to the payments in the system: what is left unmatched is what is missing on one side.')" />

@can('bank_reconciliation.edit')
<form method="POST" action="{{ route('bank-reconciliation.store') }}" enctype="multipart/form-data" class="app-card p-3 mb-4 app-form">
    @csrf
    <h2 class="h6 mb-3"><i class="bi bi-upload" aria-hidden="true"></i> {{ __('Import a statement (CSV from the bank)') }}</h2>
    @error('file')<div class="alert alert-danger">@foreach((array) $errors->get('file') as $m)<div>{{ is_array($m) ? implode(' ', $m) : $m }}</div>@endforeach</div>@enderror
    <div class="row g-3">
        <div class="col-md-5">
            <label class="form-label" for="money_account_id">{{ __('Money account') }}</label>
            <select id="money_account_id" name="money_account_id" class="form-select" required>
                @foreach($accounts as $a)<option value="{{ $a->id }}" @selected((int) old('money_account_id') === $a->id)>{{ $a->name }} · {{ $a->currency }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4"><label class="form-label" for="file">{{ __('File') }}</label><input id="file" type="file" name="file" accept=".csv,text/csv" class="form-control" required></div>
        <div class="col-md-3">
            <label class="form-label" for="date_format">{{ __('Dates are written') }}</label>
            <select id="date_format" name="date_format" class="form-select">@foreach($formats as $f)<option value="{{ $f }}" @selected(old('date_format', 'Y-m-d') === $f)>{{ now()->format($f) }}</option>@endforeach</select>
        </div>
        <div class="col-12">
            <fieldset>
                <legend class="form-label">{{ __('Which column holds what (1 = the first column)') }}</legend>
                <div class="row g-2">
                    <div class="col-6 col-md-2"><label class="form-label small" for="col_date">{{ __('Date') }}</label><input id="col_date" name="col_date" type="number" min="1" max="50" value="{{ old('col_date', 1) }}" class="form-control form-control-sm" required></div>
                    <div class="col-6 col-md-2"><label class="form-label small" for="col_description">{{ __('Description') }}</label><input id="col_description" name="col_description" type="number" min="1" max="50" value="{{ old('col_description', 2) }}" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label small" for="col_reference">{{ __('Reference') }}</label><input id="col_reference" name="col_reference" type="number" min="1" max="50" value="{{ old('col_reference') }}" class="form-control form-control-sm"></div>
                    <div class="col-6 col-md-2"><label class="form-label small" for="col_balance">{{ __('Balance') }}</label><input id="col_balance" name="col_balance" type="number" min="1" max="50" value="{{ old('col_balance') }}" class="form-control form-control-sm"></div>
                </div>
                <div class="row g-2 mt-1 align-items-end">
                    <div class="col-md-3">
                        <div class="form-check"><input class="form-check-input" type="radio" name="layout" id="layout-one" value="one" @checked(old('layout', 'one') === 'one')><label class="form-check-label" for="layout-one">{{ __('One amount column (− for money out)') }}</label></div>
                        <div class="form-check"><input class="form-check-input" type="radio" name="layout" id="layout-two" value="two" @checked(old('layout') === 'two')><label class="form-check-label" for="layout-two">{{ __('Money in and money out in two columns') }}</label></div>
                    </div>
                    <div class="col-4 col-md-2"><label class="form-label small" for="col_amount">{{ __('Amount') }}</label><input id="col_amount" name="col_amount" type="number" min="1" max="50" value="{{ old('col_amount', 3) }}" class="form-control form-control-sm"></div>
                    <div class="col-4 col-md-2"><label class="form-label small" for="col_in">{{ __('Money in') }}</label><input id="col_in" name="col_in" type="number" min="1" max="50" value="{{ old('col_in') }}" class="form-control form-control-sm"></div>
                    <div class="col-4 col-md-2"><label class="form-label small" for="col_out">{{ __('Money out') }}</label><input id="col_out" name="col_out" type="number" min="1" max="50" value="{{ old('col_out') }}" class="form-control form-control-sm"></div>
                    <div class="col-md-3"><div class="form-check"><input type="hidden" name="skip_header" value="0"><input class="form-check-input" type="checkbox" name="skip_header" id="skip_header" value="1" @checked(old('skip_header', '1') === '1')><label class="form-check-label" for="skip_header">{{ __('The first row is the column names') }}</label></div></div>
                </div>
            </fieldset>
        </div>
    </div>
    @error('file')
        <div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="anyway" id="anyway" value="1"><label class="form-check-label" for="anyway">{{ __('Import anyway (some lines are already on another statement)') }}</label></div>
    @enderror
    <button class="btn btn-primary mt-3"><i class="bi bi-upload" aria-hidden="true"></i>{{ __('Import') }}</button>
</form>
@endcan

<x-section :title="__('Statements')" icon="bank2" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Money account') }}</th><th scope="col">{{ __('Period') }}</th><th scope="col" class="text-end">{{ __('Lines') }}</th><th scope="col">{{ __('Matched') }}</th><th scope="col" class="text-end">{{ __('Closing balance') }}</th><th scope="col">{{ __('Imported by') }}</th></tr></thead>
            <tbody>
            @forelse($statements as $s)
                @php $done = $s->lines_count > 0 && $s->matched_count === $s->lines_count; @endphp
                <tr>
                    <td><a href="{{ route('bank-reconciliation.show', $s) }}" class="fw-semibold">{{ $s->moneyAccount?->name }}</a> <span class="app-unit">{{ $s->moneyAccount?->currency }}</span></td>
                    <td><span class="app-num app-ltr">{{ $s->period_from->format('Y-m-d') }} → {{ $s->period_to->format('Y-m-d') }}</span></td>
                    <td class="text-end"><span class="app-num">{{ $s->lines_count }}</span></td>
                    <td style="min-inline-size: 9rem"><x-status :tone="$done ? 'done' : 'due'">{{ $s->matched_count }} / {{ $s->lines_count }}</x-status></td>
                    <td class="app-figcell">@if($s->closing_amount !== null)<span class="app-num app-fig">{{ Decimal::format((string) $s->closing_amount) }}</span>@else<span class="app-fig app-fig--none">—</span>@endif</td>
                    <td>{{ $s->importer?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-0"><x-empty icon="bank2" :title="__('No statement imported yet.')" :text="__('Download the statement from the bank as CSV, then import it above.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
