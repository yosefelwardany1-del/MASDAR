@extends('layouts.app')

@section('title', __('New user'))

@section('content')
<h1 class="h4 mb-3">{{ __('New user') }}</h1>
<div class="aq-card p-4">
    <form method="POST" action="{{ route('users.store') }}">
        @csrf
        @include('users._form', ['user' => null])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('users.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
