@extends('layouts.app')

@section('title', __('Home'))

@section('content')
    <h1 class="h4 mb-3">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</h1>
    <div class="app-card app-empty">
        <i class="bi bi-buildings"></i>
        <p class="mb-0">{{ __('Nothing here yet. Your screens appear in the menu as each part of the system is built.') }}</p>
    </div>
@endsection
