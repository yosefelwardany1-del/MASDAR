@props([
    'name' => '',
    'src' => null,
    'size' => 36,
])
@php
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $initials = mb_substr($parts[0] ?? '', 0, 1).(count($parts) > 1 ? mb_substr(end($parts), 0, 1) : '');
    $initials = $initials !== '' ? mb_strtoupper($initials) : '?';
    // A stable colour per name, from the brand's two hues.
    $palettes = [['#1b2a4a', '#24365e'], ['#7a1f2b', '#5e1721'], ['#2f4a7a', '#1b2a4a'], ['#8f3442', '#7a1f2b']];
    [$c1, $c2] = $palettes[crc32($name ?: '?') % count($palettes)];
@endphp
{{-- Initials are always drawn; a photo is layered over them and removes itself if it
     fails to load, so a missing file never leaves an empty disc. --}}
{{-- Styles MERGE with a caller's style attribute: two style attributes on one element
     and the browser keeps only the first, losing the background. --}}
<span {{ $attributes->merge([
          'class' => 'app-avatar',
          'style' => 'inline-size:'.$size.'px;block-size:'.$size.'px;font-size:'.round($size * 0.4, 1).'px;background:linear-gradient(135deg,'.$c1.','.$c2.');',
      ]) }}
      title="{{ $name }}">
    {{ $initials }}
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}" loading="lazy" onerror="this.remove()"
             style="position:absolute;inset:0;inline-size:100%;block-size:100%;object-fit:cover;">
    @endif
</span>
