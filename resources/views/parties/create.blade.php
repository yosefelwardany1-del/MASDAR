@extends('layouts.app')

@section('title', __($kind->newLabel()))

@section('content')
<h1 class="h4 mb-3">{{ __($kind->newLabel()) }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route($kind->routePrefix().'.store') }}">
        @csrf
        @include('parties._form', ['party' => null])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route($kind->routePrefix().'.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
