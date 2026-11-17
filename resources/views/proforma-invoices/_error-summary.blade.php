{{-- What stopped a save, at the top of a form: focused after the refused save, each message a link
     to its field. The inline message under each field stays. Shared by this area's forms.
     Params: $prefix (the fields' id prefix, default ''), $bag (the error bag, default 'default'). --}}
@php $summaryErrors = $errors->getBag($bag ?? 'default'); @endphp
@if($summaryErrors->any())
    <div class="alert alert-danger app-docform-errors" role="alert" tabindex="-1" data-app-error-summary>
        <div class="app-docform-errors__title"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>{{ __('This could not be saved yet. Please check:') }}</div>
        <ul>
            @foreach($summaryErrors->messages() as $field => $messages)
                <li><a href="#{{ ($prefix ?? '').$field }}">{{ $messages[0] }}</a></li>
            @endforeach
        </ul>
    </div>
    @once
        @push('scripts')
            <script>document.querySelector('[data-app-error-summary]')?.focus();</script>
        @endpush
    @endonce
@endif
