@extends('layouts.app')

@section('title', __('Edit account'))

@section('content')
<h1 class="h4 mb-3"><span class="app-num">{{ $account->code }}</span> — {{ $account->displayName() }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('accounting.accounts.update', $account) }}">
        @csrf @method('PUT')
        @include('accounting.accounts._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route('accounting.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
