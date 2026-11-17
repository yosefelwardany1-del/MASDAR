@extends('layouts.app')

@section('title', __('Edit money account'))

@section('content')
<h1 class="h4 mb-3">{{ $account->name }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('money-accounts.update', $account) }}">
        @csrf @method('PUT')
        @include('money-accounts._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('money-accounts.show', $account) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
