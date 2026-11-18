@extends('layouts.app')

@section('title', __('CRM'))

@php
    use Carbon\CarbonImmutable;
    $channelIcons = ['call' => 'telephone', 'whatsapp' => 'whatsapp', 'wechat' => 'wechat', 'email' => 'envelope', 'meeting' => 'people', 'factory_visit' => 'building'];
    $max = max(1, $funnel->max() ?? 1, $unstaged);
@endphp

@section('content')
<x-page-header :title="__('CRM')" :subtitle="__('Where every client stands, who needs a call, and how clients find MASDAR.')">
    @can('crm.edit')
        <x-slot:actions>
            <a href="{{ route('crm.activity') }}#log" class="btn btn-primary"><i class="bi bi-journal-plus" aria-hidden="true"></i>{{ __('Log a contact') }}</a>
            @can('clients.edit')<a href="{{ route('clients.create') }}" class="btn btn-light"><i class="bi bi-person-plus" aria-hidden="true"></i>{{ __('New client') }}</a>@endcan
        </x-slot:actions>
    @endcan
</x-page-header>

@include('crm._nav', ['active' => 'home'])

<div class="row g-3 mb-4 app-kpis">
    <div class="col-6 col-lg"><x-kpi :label="__('New clients, 30 days')" :value="$kpis['new_clients']" icon="person-plus" /></div>
    <div class="col-6 col-lg"><x-kpi :label="__('Contacts this week')" :value="$kpis['contacts_week']" icon="chat-left-text" :href="route('crm.activity')" /></div>
    <div class="col-6 col-lg"><x-kpi :label="__('Follow-ups overdue')" :value="$kpis['overdue']" icon="alarm" :tone="$kpis['overdue'] ? 'late' : 'done'" :href="route('crm.tasks.index', ['filter' => 'all'])" /></div>
    <div class="col-6 col-lg"><x-kpi :label="__('Gone quiet')" :value="$kpis['quiet']" icon="hourglass-split" :tone="$kpis['quiet'] ? 'due' : 'done'" :hint="trans_choice('{1} no contact for a day|[2,*] no contact for :count days', $quietDays, ['count' => $quietDays])" /></div>
    <div class="col-12 col-lg"><x-kpi :label="__('Orders confirmed, 90 days')" :value="$kpis['won_90']" icon="trophy" tone="done" /></div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <x-section :title="__('Where the clients are')" icon="funnel" class="h-100">
            @if(Gate::allows('clients.view'))
                <x-slot:actions><a href="{{ route('crm.pipeline') }}" class="small fw-medium">{{ __('Open the pipeline') }}</a></x-slot:actions>
            @endif
            <ol class="app-crm-funnel">
                @if($unstaged)
                    <li class="app-crm-funnel__row">
                        <span class="app-crm-funnel__label text-muted">{{ __('No stage yet') }}</span>
                        <span class="app-crm-funnel__bar"><span style="inline-size: {{ round($unstaged / $max * 100) }}%"></span></span>
                        <span class="app-crm-funnel__count app-num">{{ $unstaged }}</span>
                    </li>
                @endif
                @foreach($stages as $code => $stage)
                    <li class="app-crm-funnel__row {{ $stage['closed'] ? 'is-closed' : '' }} {{ $stage['from_documents'] ? 'is-documents' : '' }}">
                        <span class="app-crm-funnel__label">
                            {{ $stage['label'] }}
                            @if($stage['from_documents'])<i class="bi bi-file-earmark-text" title="{{ __('Follows the documents') }}" aria-label="{{ __('Follows the documents') }}"></i>@endif
                        </span>
                        <span class="app-crm-funnel__bar">@if($funnel[$code] ?? 0)<span style="inline-size: {{ round($funnel[$code] / $max * 100) }}%"></span>@endif</span>
                        <span class="app-crm-funnel__count app-num">{{ $funnel[$code] ?? 0 }}</span>
                    </li>
                @endforeach
            </ol>
            <p class="app-note mt-3 mb-0"><i class="bi bi-file-earmark-text" aria-hidden="true"></i><span>{{ __('Each client\'s stage now: set by a person, or read from their latest PI, orders and containers, whichever is more recent.') }}</span></p>
        </x-section>
    </div>
    <div class="col-xl-5">
        <x-section :title="__('Gone quiet')" icon="hourglass-split" :subtitle="trans_choice('{1} No contact for a day or more|[2,*] No contact for :count days or more', $quietDays, ['count' => $quietDays])" flush class="h-100">
            @if($quiet->isEmpty())
                <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Every client has been contacted recently.') }}</div>
            @else
                <ul class="app-list">
                    @foreach($quiet as $client)
                        <li class="app-list__row">
                            <div class="app-list__main">
                                @include('crm._party-link', ['party' => $client, 'class' => 'app-list__title'])
                                <div class="app-list__sub">{{ $client->crm_stage ? ($stages[$client->crm_stage]['label'] ?? $client->crm_stage) : __('No stage yet') }}@if($client->accountManager) · <bdi>{{ $client->accountManager->name }}</bdi>@endif</div>
                            </div>
                            <span class="app-pill app-pill--due"><i class="bi bi-clock-history" aria-hidden="true"></i>
                                @if($client->last_contact_at){{ trans_choice('{0} Today|{1} a day ago|[2,*] :count days ago', $d = (int) CarbonImmutable::parse($client->last_contact_at)->startOfDay()->diffInDays(today()), ['count' => $d]) }}@else{{ __('Never contacted') }}@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section>
    </div>

    <div class="col-xl-7">
        <x-section :title="__('Latest contacts')" icon="chat-left-text" flush class="h-100">
            <x-slot:actions><a href="{{ route('crm.activity') }}" class="small fw-medium">{{ __('All activity') }}</a></x-slot:actions>
            @include('crm._timeline', ['interactions' => $recent, 'channelIcons' => $channelIcons, 'channels' => $channels, 'compact' => true])
        </x-section>
    </div>
    <div class="col-xl-5">
        <x-section :title="__('How clients find MASDAR')" icon="signpost-split" flush class="mb-3">
            @if($sources->isEmpty())
                <div class="app-panel-empty">{{ __('No clients yet.') }}</div>
            @else
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Source') }}</th><th scope="col" class="text-end">{{ __('Clients') }}</th><th scope="col" class="text-end">{{ __('Confirmed an order') }}</th></tr></thead>
                    <tbody>
                    @foreach($sources as $s)
                        <tr>
                            <td>{{ $s->label }}</td>
                            <td class="text-end"><span class="app-num">{{ $s->clients }}</span></td>
                            <td class="text-end"><span class="app-num">{{ $s->won }}</span> <span class="app-unit">{{ $s->clients ? round($s->won / $s->clients * 100) : 0 }}%</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-section>
        @if($team->isNotEmpty())
            <x-section :title="__('Contacts by person, 30 days')" icon="people" flush>
                <table class="table align-middle mb-0">
                    <thead><tr><th scope="col">{{ __('Person') }}</th><th scope="col" class="text-end">{{ __('Contacts') }}</th><th scope="col" class="text-end">{{ __('Parties') }}</th></tr></thead>
                    <tbody>
                    @foreach($team as $t)
                        <tr><td><bdi>{{ $t->name }}</bdi></td><td class="text-end"><span class="app-num">{{ $t->contacts }}</span></td><td class="text-end"><span class="app-num">{{ $t->parties }}</span></td></tr>
                    @endforeach
                    </tbody>
                </table>
            </x-section>
        @endif
    </div>
</div>
@endsection
