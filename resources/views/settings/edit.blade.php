@extends('layouts.app')

@section('title', __('Company settings'))

@section('content')
<h1 class="h4 mb-3">{{ __('Company settings') }}</h1>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="app-card p-4">
            <form method="POST" action="{{ route('settings.update') }}">
                @csrf @method('PUT')
                <fieldset @disabled(! $editable)>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Company name (Arabic)') }}</label>
                        <input type="text" name="company_name_ar" class="form-control @error('company_name_ar') is-invalid @enderror" value="{{ old('company_name_ar', $setting->company_name_ar) }}" required>
                        @error('company_name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">{{ __('Company name (English)') }}</label>
                        <input type="text" name="company_name" dir="ltr" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $setting->company_name) }}" required>
                        @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if($editable)<button class="btn btn-primary">{{ __('Save') }}</button>@endif
                </fieldset>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="app-card p-4 text-center">
            <h2 class="h6 mb-3">{{ __('Logo') }}</h2>
            <x-image-upload :src="$setting->logo ? route('settings.logo') : null" field="logo" :name="$setting->company_name_ar"
                            :action="route('settings.logo.store')" :remove="route('settings.logo.destroy')"
                            shape="square" :size="120" :label="__('Upload logo')" :editable="$editable" />
        </div>
    </div>
</div>
@endsection
