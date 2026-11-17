{{-- A select over code => name options (a Lookup list, users, parties). A current value that is
     no longer offered (a switched-off list entry) stays selected rather than silently dropped.
     Params: $name, $label, $options, $value; optional $required, $placeholder, $bag, $id. --}}
@php
    $bagName = $bag ?? 'default';
    $fieldId = $id ?? 'f-'.$name;
    $current = (string) old($name, $value ?? '');
    $isRequired = $required ?? false;
    $fieldErrors = $errors->getBag($bagName);
@endphp
<label class="form-label" for="{{ $fieldId }}">{{ $label }}@if($isRequired) <span class="text-danger">*</span>@endif</label>
<select name="{{ $name }}" id="{{ $fieldId }}" class="form-select {{ $fieldErrors->has($name) ? 'is-invalid' : '' }}" @if($isRequired) required @endif>
    @unless($isRequired)<option value="">{{ $placeholder ?? '—' }}</option>@endunless
    @foreach($options as $code => $text)
        <option value="{{ $code }}" @selected($current === (string) $code)>{{ $text }}</option>
    @endforeach
    @if($current !== '' && ! array_key_exists($current, $options))
        <option value="{{ $current }}" selected>{{ $current }}</option>
    @endif
</select>
@if($fieldErrors->has($name))<div class="invalid-feedback">{{ $fieldErrors->first($name) }}</div>@endif
