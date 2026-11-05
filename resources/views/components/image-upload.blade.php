@props([
    'src' => null,          // current image URL, or null for the initials placeholder
    'field' => 'photo',     // field name the controller validates
    'action' => null,       // POST endpoint
    'remove' => null,       // DELETE endpoint (optional)
    'name' => '',           // for the initials placeholder
    'size' => 96,
    'shape' => 'circle',    // circle for people, square for marks
    'label' => null,
    'editable' => true,
])
{{-- One picture-upload widget. It posts on file selection and is its OWN form — a nested
     <form> is invalid HTML and the browser silently drops the inner one. --}}
@php
    $radius = $shape === 'circle' ? '50%' : '10px';
    $formId = 'imgup-'.\Illuminate\Support\Str::random(6);
@endphp
<div {{ $attributes->merge(['class' => 'text-center']) }}>
    @if($src)
        <div style="inline-size:{{ $size }}px;block-size:{{ $size }}px;border-radius:{{ $radius }};margin-inline:auto;border:1px solid var(--app-line);background:#fff url('{{ $src }}') center/cover no-repeat;"></div>
    @else
        <x-avatar :name="$name" :size="$size" style="border-radius:{{ $radius }};" />
    @endif

    @if($editable && $action)
        <form method="POST" action="{{ $action }}" enctype="multipart/form-data" id="{{ $formId }}" class="mt-2">
            @csrf
            <label class="text-primary small" style="cursor:pointer;">
                <input type="file" name="{{ $field }}" accept="image/jpeg,image/png,image/webp" hidden
                       onchange="document.getElementById('{{ $formId }}').submit()">
                <i class="bi bi-upload me-1"></i>{{ $src ? __('Change') : ($label ?? __('Add photo')) }}
            </label>
        </form>
        @if($src && $remove)
            <form method="POST" action="{{ $remove }}" onsubmit="return confirm(@js(__('Remove this image?')))">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-link text-danger p-0 small">{{ __('Remove') }}</button>
            </form>
        @endif
        @error($field)<div class="text-danger small">{{ $message }}</div>@enderror
        <div class="text-muted" style="font-size:.7rem;">{{ __('JPG, PNG or WebP, up to 4 MB') }}</div>
    @endif
</div>
