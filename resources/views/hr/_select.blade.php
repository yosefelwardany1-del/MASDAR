{{-- A list-backed select: $name, $label, $options (code => label), $value; blank allowed. --}}
@php $id = 'f-'.$name; $current = old($name, $value); @endphp
<label class="form-label" for="{{ $id }}">{{ $label }}@if(! empty($required))<span class="app-req" aria-hidden="true">*</span>@endif</label>
<select name="{{ $name }}" id="{{ $id }}" class="form-select @error($name) is-invalid @enderror" @required(! empty($required))>
    <option value="">{{ empty($required) ? '—' : __('Choose…') }}</option>
    @foreach($options as $code => $optionLabel)
        <option value="{{ $code }}" @selected((string) $current === (string) $code)>{{ $optionLabel }}</option>
    @endforeach
    @if($current !== null && $current !== '' && ! array_key_exists($current, $options))
        <option value="{{ $current }}" selected>{{ $current }}</option>
    @endif
</select>
@error($name)<div class="invalid-feedback">{{ $message }}</div>@enderror
