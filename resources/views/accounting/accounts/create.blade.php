@extends('layouts.app')

@section('title', __('New account'))

@section('content')
<h1 class="h4 mb-3">{{ __('New account') }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('accounting.accounts.store') }}">
        @csrf
        @include('accounting.accounts._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('accounting.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
