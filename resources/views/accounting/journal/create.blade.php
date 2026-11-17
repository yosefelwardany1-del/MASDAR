@extends('layouts.app')

@section('title', __('New journal entry'))

@section('content')
<x-page-header :title="__('New journal entry')" :back="route('accounting.journal.index')" :back-label="__('Journal')"
               :subtitle="__('A manual entry is an adjustment: it is saved as a draft and posted by a manager.')" />
<form method="POST" action="{{ route('accounting.journal.store') }}" class="app-form">
    @csrf
    @include('accounting.journal._form', ['entry' => null])
    <div class="app-form__actions">
        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save draft') }}</button>
        <a href="{{ route('accounting.journal.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
    </div>
</form>
@endsection
