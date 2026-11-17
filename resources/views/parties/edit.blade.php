@extends('layouts.app')

@section('title', $party->code.' · '.$party->name)

@section('content')
<h1 class="h4 mb-3"><span class="app-num text-muted me-2">{{ $party->code }}</span>{{ $party->name }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route($kind->routePrefix().'.update', $party) }}">
        @csrf @method('PUT')
        @include('parties._form')
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ route($kind->routePrefix().'.show', $party) }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
