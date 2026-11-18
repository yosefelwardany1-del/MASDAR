@extends('layouts.app')

@section('title', $party->code.' · '.$party->name)

@php
    use App\Models\BusinessSetting;
    use Illuminate\Support\HtmlString;

    $prefix = $kind->routePrefix();
    $screen = $kind->screen();
    $label = fn (string $list, ?string $code) => $code === null || $code === '' ? '—' : ($labels[$list][$code] ?? $code);
    $settings = BusinessSetting::current();
    $deposit = $party->deposit_percent ?? $settings->default_deposit_percent;
    $lines = fn (?string $text) => $text ? new HtmlString(nl2br(e($text))) : '—';
    $rtl = \App\Http\Middleware\SetUserLocale::isRtl();

    // Initials for the profile mark, as the avatar component makes them.
    $words = preg_split('/\s+/u', trim($party->name)) ?: [];
    $initials = mb_strtoupper(mb_substr($words[0] ?? '?', 0, 1).(count($words) > 1 ? mb_substr(end($words), 0, 1) : ''));

    $contact = $party->primaryContact();
    $overdue = $openTasks->filter->isOverdue()->count();
    $documents = collect($related)->sum(fn ($rows) => $rows->count()) + $party->attachments->count();

    // The tab that opens first: the one whose form was just refused, else Contacts.
    $tab = match (true) {
        $errors->getBag('interaction')->any(), $errors->getBag('task')->any(), $errors->has('task') => 'history',
        $errors->has('files'), $errors->has('files.*') => 'documents',
        default => 'contacts',
    };
    $tabs = [
        'contacts' => ['person-lines-fill', __('Contacts'), $party->contacts->count()],
        'history' => ['clock-history', __('History'), $interactions->count() + $openTasks->count()],
        'documents' => ['folder2-open', __('Documents'), $documents],
        'money' => ['wallet2', __('Money'), null],
    ];
@endphp

@section('content')
<a href="{{ route($prefix.'.index') }}" class="app-pagehead__back"><i class="bi {{ $rtl ? 'bi-arrow-right' : 'bi-arrow-left' }}" aria-hidden="true"></i>{{ __($kind->plural()) }}</a>

{{-- The profile: who they are, then the facts staff look up most. --}}
<section class="app-card app-profile" aria-labelledby="party-name">
    <div class="app-profile__top">
        <span class="app-profile__mark" aria-hidden="true">{{ $initials }}</span>
        <div class="app-profile__id">
            <div class="app-profile__titlerow">
                <h1 class="app-profile__name" id="party-name"><bdi>{{ $party->name }}</bdi></h1>
                @foreach($party->kinds() as $k)<span class="app-pill app-pill--info"><i class="bi {{ $k->icon() }}" aria-hidden="true"></i>{{ __($k->label()) }}</span>@endforeach
                @if($party->active)
                    <span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Active') }}</span>
                @else
                    <span class="app-pill app-pill--skip"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Inactive') }}</span>
                @endif
            </div>
            <div class="app-profile__sub">
                <span class="app-doc-code">{{ $party->code }}</span>
                @if($party->name_local)<bdi class="app-profile__local">{{ $party->name_local }}</bdi>@endif
                @if($party->is_client && $party->stage)<span class="app-profile__stage"><i class="bi bi-signpost" aria-hidden="true"></i>{{ $label('client_stage', $party->stage) }}</span>@endif
                @if($openTasks->isNotEmpty())
                    <a href="#history" class="app-pill {{ $overdue ? 'app-pill--late' : 'app-pill--due' }} text-decoration-none" data-app-tab-link>
                        <i class="bi {{ $overdue ? 'bi-exclamation-triangle' : 'bi-check2-square' }}" aria-hidden="true"></i>{{ __('Open tasks') }} <span class="app-num">{{ $openTasks->count() }}</span>
                        @if($overdue)<span>· {{ __('Overdue') }} <span class="app-num">{{ $overdue }}</span></span>@endif
                    </a>
                @endif
            </div>
        </div>
        @can($screen.'.edit')
            <div class="app-profile__actions">
                <a href="{{ route($prefix.'.edit', $party) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }}</a>
                @if($party->active)
                    <form method="POST" action="{{ route($prefix.'.deactivate', $party) }}" onsubmit="return confirm(@js(__('Deactivate this party? It stays on every document that refers to it.')));">
                        @csrf<button class="btn btn-outline-danger"><i class="bi bi-slash-circle" aria-hidden="true"></i>{{ __('Deactivate') }}</button>
                    </form>
                @else
                    <form method="POST" action="{{ route($prefix.'.reactivate', $party) }}">
                        @csrf<button class="btn btn-light"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reactivate') }}</button>
                    </form>
                @endif
            </div>
        @endcan
    </div>

    <dl class="app-details app-profile__facts">
        <div class="app-details__item"><dt>{{ __('Country') }}</dt><dd>{{ $label('country', $party->country) }}@if($party->city) · <bdi>{{ $party->city }}</bdi>@endif</dd></div>
        <div class="app-details__item"><dt>{{ __('Default currency') }}</dt><dd>{{ $label('currency', $party->currency) }}</dd></div>
        <div class="app-details__item"><dt>{{ __('Primary contact') }}</dt>
            <dd>
                @if($contact)
                    <bdi>{{ $contact->name }}</bdi>
                    <span class="app-profile__reach">
                        @if($contact->phone)<a href="tel:{{ $contact->phone }}" class="app-iconlink" title="{{ __('Phone') }}"><i class="bi bi-telephone" aria-hidden="true"></i><span class="visually-hidden">{{ __('Phone') }} {{ $contact->phone }}</span></a>@endif
                        @if($contact->whatsapp)<a href="https://wa.me/{{ ltrim($contact->whatsapp, '+') }}" target="_blank" rel="noopener" class="app-iconlink" title="{{ __('WhatsApp') }}"><i class="bi bi-whatsapp" aria-hidden="true"></i><span class="visually-hidden">{{ __('WhatsApp') }} {{ $contact->whatsapp }}</span></a>@endif
                        @if($contact->email)<a href="mailto:{{ $contact->email }}" class="app-iconlink" title="{{ __('E-mail') }}"><i class="bi bi-envelope" aria-hidden="true"></i><span class="visually-hidden">{{ __('E-mail') }} {{ $contact->email }}</span></a>@endif
                    </span>
                @else — @endif
            </dd></div>

        @if($party->is_client)
            <div class="app-details__item"><dt>{{ __('Account manager') }}</dt><dd><bdi>{{ $party->accountManager?->name ?? '—' }}</bdi></dd></div>
            <div class="app-details__item"><dt>{{ __('Stage') }}</dt><dd>{{ $label('client_stage', $party->stage) }}</dd></div>
            <div class="app-details__item"><dt>{{ __('How they found us') }}</dt><dd>{{ $label('party_source', $party->source) }}</dd></div>
            <div class="app-details__item"><dt>{{ __('Shipped to') }}</dt><dd><bdi>{{ $party->default_destination ?: '—' }}</bdi></dd></div>
            <div class="app-details__item app-profile__wide"><dt>{{ __('Payment terms') }}</dt>
                <dd><span class="app-ltr">{{ $settings->piTerms($deposit) }}</span>@if($party->deposit_percent === null) <span class="text-muted small fw-normal">({{ __('business default') }})</span>@endif</dd></div>
        @endif

        @if($party->is_shipping)
            <div class="app-details__item"><dt>{{ __('Kind of shipping company') }}</dt><dd>{{ $label('shipping_company_kind', $party->shipping_kind) }}</dd></div>
        @endif

        @if($party->is_supplier)
            <div class="app-details__item app-profile__wide"><dt>{{ __('What they make') }}</dt><dd dir="auto">{{ $lines($party->products_note) }}</dd></div>
            @if($party->factory_address)
                <div class="app-details__item app-profile__wide"><dt>{{ __('Factory or market booth address') }}</dt><dd dir="auto">{{ $lines($party->factory_address) }}</dd></div>
            @endif
            @if($canSeeBank && $party->bank_details)
                <div class="app-details__item app-profile__wide"><dt><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Bank or payment details') }}</dt><dd dir="auto" data-test="bank-details">{{ $lines($party->bank_details) }}</dd></div>
            @endif
        @endif

        {{-- Long notes only when there are some; Edit is where they are added. --}}
        @if($party->address)<div class="app-details__item app-profile__wide"><dt>{{ __('Address') }}</dt><dd dir="auto">{{ $lines($party->address) }}</dd></div>@endif
        @if($party->notes)<div class="app-details__item app-profile__wide"><dt>{{ __('Notes') }}</dt><dd dir="auto">{{ $lines($party->notes) }}</dd></div>@endif
    </dl>
</section>

{{-- Four sections, one at a time. Each is a real anchor (#contacts, #history, #documents, #money),
     so a link or a redirect opens it; without :has() support every section simply shows in turn. --}}
<div class="app-ptabs" data-app-tabs data-default="{{ $tab }}">
    <nav class="app-ptabs__nav" aria-label="{{ __('Sections of this page') }}">
        @foreach($tabs as $id => [$icon, $name, $count])
            <a href="#{{ $id }}" class="app-ptabs__link {{ $id === $tab ? 'is-default' : '' }}" data-app-tab-link>
                <i class="bi bi-{{ $icon }}" aria-hidden="true"></i><span>{{ $name }}</span>
                @if($count !== null)<span class="app-ptabs__count app-num">{{ $count }}</span>@endif
            </a>
        @endforeach
    </nav>

    <div class="app-ptabs__panels">
        <section class="app-ptabs__panel {{ $tab === 'contacts' ? 'is-default' : '' }}" id="contacts" aria-labelledby="contacts-head" tabindex="-1" data-app-tab-panel>
            @include('parties._contacts')
        </section>

        <section class="app-ptabs__panel {{ $tab === 'history' ? 'is-default' : '' }}" id="history" aria-labelledby="history-head" tabindex="-1" data-app-tab-panel>
            <h2 class="visually-hidden" id="history-head">{{ __('History') }}</h2>
            <div class="row g-3">
                <div class="col-xl-7">@include('parties._interactions')</div>
                <div class="col-xl-5">@include('parties._tasks')</div>
            </div>
        </section>

        <section class="app-ptabs__panel {{ $tab === 'documents' ? 'is-default' : '' }}" id="documents" aria-labelledby="documents-head" tabindex="-1" data-app-tab-panel>
            <h2 class="visually-hidden" id="documents-head">{{ __('Documents') }}</h2>
            @include('parties._related')
            <div class="app-ptabs__attach">
                @include('partials.attachments', ['attachable' => $party, 'type' => 'parties'])
            </div>
        </section>

        <section class="app-ptabs__panel" id="money" aria-labelledby="money-head" tabindex="-1" data-app-tab-panel>
            @include('parties._money')
        </section>
    </div>
</div>
@endsection

@include('partials.ptabs-script')
