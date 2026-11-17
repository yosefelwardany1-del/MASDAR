@extends('layouts.app')

@section('title', __('New product'))

@section('content')
<h1 class="h4 mb-3">{{ __('New product') }}</h1>
<div class="app-card p-4">
    <form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data">
        @csrf
        @include('products._form', ['product' => null])
        <div class="mt-4 d-flex gap-2">
            <button class="btn btn-primary">{{ __('Create') }}</button>
            <a href="{{ route('products.index') }}" class="btn btn-light">{{ __('Cancel') }}</a>
        </div>
    </form>
</div>
@endsection
