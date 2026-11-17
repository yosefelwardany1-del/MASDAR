@extends('layouts.app')

@section('title', __('Edit draft entry'))

@section('content')
<h1 class="h4 mb-3">{{ __('Edit draft entry') }} <span class="text-muted">{{ $entry->displayNumber() }}</span></h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('accounting.journal.update', $entry) }}">
        @csrf @method('PUT')
        @include('accounting.journal._form', ['entry' => $entry])
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save draft') }}</button>
            <a href="{{ route('accounting.journal.show', $entry) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
