@extends('layouts.app')

@section('title', __('Edit draft entry'))

@section('content')
<x-page-header :title="__('Edit draft entry')" :back="route('accounting.journal.show', $entry)" :back-label="$entry->displayNumber()"
               :subtitle="__('A manual entry is an adjustment: it is saved as a draft and posted by a manager.')" />
<form method="POST" action="{{ route('accounting.journal.update', $entry) }}" class="app-form">
    @csrf @method('PUT')
    @include('accounting.journal._form', ['entry' => $entry])
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save draft') }}</button>
        <a href="{{ route('accounting.journal.show', $entry) }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
