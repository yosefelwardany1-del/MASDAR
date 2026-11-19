@extends('layouts.app')

@section('title', __('Opening balances'))

@section('content')
<x-page-header :title="__('Opening balances')" :eyebrow="__('Accounting')"
               :subtitle="__('What MASDAR has and owes on the day the books start in the system: bank balances, what each client and factory owes or is owed, other balances. Agreed with the accountant.')" />
@include('accounting._nav', ['active' => 'opening'])

<div class="row g-3">
    <div class="col-lg-7">
        <x-section :title="__('How to bring them in')" icon="info-circle">
            <ol class="mb-3">
                <li>{{ __('Download the file below and fill one row per balance.') }}</li>
                <li>{{ __('A client\'s or supplier\'s open invoice goes on its own row, dated as the invoice, with the party\'s code: the ageing report then ages it right.') }}</li>
                <li>{{ __('Amounts are + for a debit (an asset, money owed to MASDAR) and − for a credit (money MASDAR owes). Another currency than :book takes its rate, or the rate of that date is used.', ['book' => $book]) }}</li>
                <li>{{ __('Each date becomes one draft entry, balanced against Opening balance equity. Check the drafts on the Journal screen; a manager posts them.') }}</li>
            </ol>
            <p class="small text-muted mb-2">{{ __('Columns:') }} <span class="app-ltr">{{ implode(', ', $columns) }}</span></p>
            <a href="{{ route('accounting.opening.template') }}" class="btn btn-light"><i class="bi bi-download" aria-hidden="true"></i>{{ __('Download the file to fill') }}</a>
        </x-section>
    </div>
    <div class="col-lg-5">
        <form method="POST" action="{{ route('accounting.opening.import') }}" enctype="multipart/form-data" class="app-card p-3 app-form">
            @csrf
            <h2 class="h6 mb-3"><i class="bi bi-upload" aria-hidden="true"></i> {{ __('Import the filled file') }}</h2>
            @error('file')<div class="alert alert-danger small">@foreach((array) $errors->get('file') as $m)<div>{{ is_array($m) ? implode(' ', $m) : $m }}</div>@endforeach</div>@enderror
            <input type="file" name="file" accept=".csv,text/csv" class="form-control mb-3" required aria-label="{{ __('File') }}">
            <button class="btn btn-primary"><i class="bi bi-upload" aria-hidden="true"></i>{{ __('Make the draft entries') }}</button>
        </form>
    </div>
</div>
@endsection
