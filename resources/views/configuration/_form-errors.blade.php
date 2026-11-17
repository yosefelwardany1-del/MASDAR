{{-- The summary of a failed submit, at the top of a form (the form pattern in screens/finance.css).
     Each field keeps its own error right under it; this lists them all once and takes the focus,
     so a keyboard or screen-reader user lands on what went wrong. $bag (optional): an error bag name. --}}
@php $bagErrors = isset($bag) ? $errors->getBag($bag) : $errors; @endphp
@if($bagErrors->any())
    <div class="alert alert-danger app-errsum mb-0" role="alert" tabindex="-1" data-app-errsum>
        <div class="app-errsum__title"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>{{ __('Please correct the fields marked below.') }}</div>
        @if(count($bagErrors->all()) > 1)
            <ul>@foreach(array_unique($bagErrors->all()) as $message)<li>{{ $message }}</li>@endforeach</ul>
        @endif
    </div>
    @once
        @push('scripts')
            <script>document.querySelector('[data-app-errsum]')?.focus();</script>
        @endpush
    @endonce
@endif
