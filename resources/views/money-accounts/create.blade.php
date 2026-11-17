@extends('layouts.app')

@section('title', __('New money account'))

@section('content')
<h1 class="h4 mb-3">{{ __('New money account') }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('money-accounts.store') }}">
        @csrf
        @include('money-accounts._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('money-accounts.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
