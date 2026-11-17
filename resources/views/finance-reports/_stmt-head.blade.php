{{-- The heading of a statement, as on paper: the company, the statement, its period and currency.
     $title; either $from and $to, or $asAt; $book. --}}
@php
    $setting = \App\Models\Setting::current();
    $locale = app()->getLocale();
    $long = fn ($date) => \Illuminate\Support\Carbon::parse($date)->locale($locale)->translatedFormat(['zh_CN' => 'Y年n月j日'][$locale] ?? 'j F Y');
@endphp
<div class="app-stmt-head">
    <div class="app-stmt-head__company">{{ $locale === 'ar' ? $setting->company_name_ar : $setting->company_name }}</div>
    <h2 class="app-stmt-head__title">{{ $title }}</h2>
    <div class="app-stmt-head__period">
        @isset($asAt)
            {{ __('As at :date', ['date' => $long($asAt)]) }}
        @else
            {{ __('From :from to :to', ['from' => $long($from), 'to' => $long($to)]) }}
        @endisset
        · {{ __('Amounts in :book', ['book' => $book]) }}
    </div>
</div>
