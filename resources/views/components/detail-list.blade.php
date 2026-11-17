{{-- Label/value pairs in a responsive grid. :items is [label => value]; a value may be HtmlString.
     Empty values show an em dash. cols: 1–3. --}}
@props(['items' => [], 'cols' => 2])
<dl {{ $attributes->class(['app-details', 'app-details--cols-'.$cols]) }}>
    @foreach($items as $label => $value)
        <div class="app-details__item">
            <dt>{{ $label }}</dt>
            <dd>{{ (is_string($value) && trim($value) === '') || $value === null ? '—' : $value }}</dd>
        </div>
    @endforeach
</dl>
