{{-- Shared by create and edit. $account is new (not saved) on create. --}}
@include('configuration._form-errors')
<p class="app-form__legend"><span class="app-req" aria-hidden="true">*</span>{{ __('Required field') }}</p>

<x-section :title="__('The account')" icon="journal-bookmark">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label" for="code">{{ __('Code') }}<span class="app-req" aria-hidden="true">*</span></label>
            @if($account->exists)
                <input id="code" class="form-control app-num" dir="ltr" value="{{ $account->code }}" disabled aria-describedby="code-help">
                <div class="form-text" id="code-help"><i class="bi bi-lock me-1" aria-hidden="true"></i>{{ __('A code never changes.') }}</div>
            @else
                <input id="code" name="code" dir="ltr" class="form-control app-num @error('code') is-invalid @enderror" value="{{ old('code') }}" required maxlength="20">
                @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
            @endif
        </div>
        <div class="col-md-5">
            <label class="form-label" for="name">{{ __('Name (English)') }}<span class="app-req" aria-hidden="true">*</span></label>
            <input id="name" name="name" dir="ltr" class="form-control @error('name') is-invalid @enderror" value="{{ old('name', $account->name) }}" required maxlength="160">
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-4">
            <label class="form-label" for="type">{{ __('Type') }}<span class="app-req" aria-hidden="true">*</span></label>
            <select id="type" name="type" class="form-select @error('type') is-invalid @enderror" @disabled($hasLines) @if($hasLines) aria-describedby="type-help" @endif>
                @foreach($types as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $account->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
            @if($hasLines)
                <input type="hidden" name="type" value="{{ $account->type }}">
                <div class="form-text" id="type-help"><i class="bi bi-lock me-1" aria-hidden="true"></i>{{ __('The account has postings; its type cannot change.') }}</div>
            @endif
            @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</x-section>

<x-section :title="__('Names in other languages')" icon="translate">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="name_ar">{{ __('Name (Arabic)') }}</label>
            <input id="name_ar" name="name_ar" dir="rtl" lang="ar" class="form-control" value="{{ old('name_ar', $account->name_ar) }}" maxlength="160">
        </div>
        <div class="col-md-6">
            <label class="form-label" for="name_zh">{{ __('Name (Chinese)') }}</label>
            <input id="name_zh" name="name_zh" lang="zh-CN" class="form-control" value="{{ old('name_zh', $account->name_zh) }}" maxlength="160">
        </div>
    </div>
</x-section>

<x-section :title="__('Place in the chart')" icon="diagram-3">
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label" for="parent_id">{{ __('Under heading') }}</label>
            <select id="parent_id" name="parent_id" class="form-select @error('parent_id') is-invalid @enderror" aria-describedby="parent-help">
                <option value="">{{ __('— top level —') }}</option>
                @foreach($headings as $heading)
                    <option value="{{ $heading->id }}" @selected((string) old('parent_id', $account->parent_id) === (string) $heading->id)>{{ $heading->label() }}</option>
                @endforeach
            </select>
            @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text" id="parent-help">{{ __('A sub-account has the type of its heading.') }}</div>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="currency">{{ __('Currency') }}</label>
            <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
                <option value="">{{ __('Any currency') }}</option>
                @foreach($currencies as $code => $name)
                    <option value="{{ $code }}" @selected(old('currency', $account->currency) === $code)>{{ $code }} — {{ $name }}</option>
                @endforeach
            </select>
            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sort">{{ __('Sort') }}</label>
            <input id="sort" name="sort" type="number" min="0" class="form-control" value="{{ old('sort', $account->sort) }}">
        </div>
        <div class="col-12">
            <div class="app-switch">
                <input type="hidden" name="is_postable" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="is_postable" name="is_postable" value="1" @checked(old('is_postable', $account->is_postable))>
                    <label class="form-check-label" for="is_postable">{{ __('Takes postings (off: a heading that groups sub-accounts)') }}</label>
                </div>
            </div>
            <div class="app-switch">
                <input type="hidden" name="active" value="0">
                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" @checked(old('active', $account->active))>
                    <label class="form-check-label" for="active">{{ __('Active') }}</label>
                </div>
            </div>
        </div>
    </div>
</x-section>
