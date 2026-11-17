{{-- Port of loading → port of discharge, the arrow following the page's direction. Needs $ports (code => name), $pol, $pod. --}}
<span class="text-nowrap">{{ $pol ? ($ports[$pol] ?? $pol) : '—' }}
<i class="bi {{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'bi-arrow-left' : 'bi-arrow-right' }} mx-1 text-muted" aria-hidden="true"></i><span class="visually-hidden">{{ __('To') }}</span>
{{ $pod ? ($ports[$pod] ?? $pod) : '—' }}</span>
