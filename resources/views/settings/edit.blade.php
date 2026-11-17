@extends('layouts.app')

@section('title', __('Company settings'))

@section('content')
<x-page-header :title="__('Company settings')" :subtitle="__('The company\'s name and logo, as they appear in the menu and on documents.')">
    @unless($editable)
        <x-slot:meta><x-status tone="neutral" icon="eye">{{ __('Read only') }}</x-status></x-slot:meta>
    @endunless
</x-page-header>
<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST" action="{{ route('settings.update') }}" class="app-form">
            @csrf @method('PUT')
            @include('configuration._form-errors')
            <fieldset @disabled(! $editable) class="app-form">
                @if($editable)<p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>@endif
                <x-section :title="__('Company name')" icon="building">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="s-name-ar">{{ __('Company name (Arabic)') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="text" name="company_name_ar" id="s-name-ar" dir="rtl" lang="ar" class="form-control @error('company_name_ar') is-invalid @enderror" value="{{ old('company_name_ar', $setting->company_name_ar) }}" required>
                            @error('company_name_ar')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="s-name">{{ __('Company name (English)') }}<span class="app-req" aria-hidden="true">*</span></label>
                            <input type="text" name="company_name" id="s-name" dir="ltr" class="form-control @error('company_name') is-invalid @enderror" value="{{ old('company_name', $setting->company_name) }}" required>
                            @error('company_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </x-section>
                @if($editable)
                    <div class="app-form__actions">
                        <button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save') }}</button>
                    </div>
                @endif
            </fieldset>
        </form>
    </div>
    <div class="col-lg-4">
        <x-section :title="__('Logo')" icon="image">
            <x-image-upload :src="$setting->logo ? route('settings.logo') : null" field="logo" :name="$setting->company_name_ar"
                            :action="route('settings.logo.store')" :remove="route('settings.logo.destroy')"
                            shape="square" :size="120" :label="__('Upload logo')" :editable="$editable" />
        </x-section>
    </div>
</div>
@endsection
