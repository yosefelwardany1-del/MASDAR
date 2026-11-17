@extends('layouts.app')

@section('title', __('New journal entry'))

@section('content')
<h1 class="h4 mb-3">{{ __('New journal entry') }}</h1>
<p class="text-muted small">{{ __('A manual entry is an adjustment: it is saved as a draft and posted by a manager.') }}</p>
<div class="app-card p-4">
    <form method="POST" action="{{ route('accounting.journal.store') }}">
        @csrf
        @include('accounting.journal._form', ['entry' => null])
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save draft') }}</button>
            <a href="{{ route('accounting.journal.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
