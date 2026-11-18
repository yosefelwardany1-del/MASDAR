@extends('layouts.app')

@section('title', __('Pipeline'))

@php
    use Carbon\CarbonImmutable;
    $typed = array_filter($stages, fn ($s) => ! $s['from_documents']);
    $columns = ['' => ['label' => __('No stage yet'), 'from_documents' => false, 'closed' => false]] + $stages;
    $byStage = $clients->groupBy(fn ($c) => $c->crm_stage ?? '');
@endphp

@section('content')
<x-page-header :title="__('Pipeline')" :subtitle="__('Every client by stage. Move a client between the stages you set; the stages from \'PI sent\' on follow the PI, the factory orders and the containers.')" />

@include('crm._nav', ['active' => 'pipeline'])

@if($managers->isNotEmpty())
    <form method="GET" class="d-flex flex-wrap align-items-center gap-2 mb-3">
        <label class="small fw-medium" for="pl-manager">{{ __('Account manager') }}</label>
        <select name="manager" id="pl-manager" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <option value="">{{ __('Everyone') }}</option>
            @foreach($managers as $m)<option value="{{ $m->id }}" @selected($manager === $m->id)>{{ $m->name }}</option>@endforeach
        </select>
        <noscript><button class="btn btn-sm btn-light">{{ __('Filter') }}</button></noscript>
    </form>
@endif

<div class="app-crm-board" data-board>
    @foreach($columns as $code => $stage)
        @php $cards = $byStage->get($code, collect()); @endphp
        @continue($code === '' && $cards->isEmpty())
        <section class="app-crm-col {{ $stage['from_documents'] ? 'is-documents' : 'is-typed' }} {{ $stage['closed'] ? 'is-closed' : '' }}"
                 aria-labelledby="col-{{ $code ?: 'none' }}" @if($canMove && $code !== '' && ! $stage['from_documents']) data-drop="{{ $code }}" @endif>
            <header class="app-crm-col__head" id="col-{{ $code ?: 'none' }}">
                <span class="app-crm-col__title">{{ $stage['label'] }}</span>
                <span class="app-crm-col__count app-num">{{ $cards->count() }}</span>
                @if($stage['from_documents'])<i class="bi bi-file-earmark-text ms-auto" title="{{ __('Follows the documents') }}"></i><span class="visually-hidden">{{ __('Follows the documents') }}</span>@endif
            </header>
            <ul class="app-crm-col__cards">
                @forelse($cards as $client)
                    @php
                        $days = $client->last_contact_at ? (int) CarbonImmutable::parse($client->last_contact_at)->startOfDay()->diffInDays(today()) : null;
                        $nextDue = $client->next_task_on ? CarbonImmutable::parse($client->next_task_on) : null;
                    @endphp
                    <li class="app-crm-card" @if($canMove) draggable="true" data-card="{{ $client->id }}" @endif>
                        <div class="app-crm-card__top">
                            <x-avatar :name="$client->name" :size="28" />
                            <div class="min-w-0">
                                @include('crm._party-link', ['party' => $client, 'class' => 'app-crm-card__name'])
                                <div class="app-crm-card__sub"><span class="app-ltr">{{ $client->code }}</span>@if($client->accountManager) · <bdi>{{ $client->accountManager->name }}</bdi>@endif</div>
                            </div>
                        </div>
                        <div class="app-crm-card__facts">
                            <span title="{{ __('Last contact') }}"><i class="bi bi-chat-left-text" aria-hidden="true"></i>
                                @if($days === null){{ __('Never contacted') }}@elseif($days === 0){{ __('Today') }}@else{{ trans_choice('{1} a day ago|[2,*] :count days ago', $days, ['count' => $days]) }}@endif
                            </span>
                            @if($client->open_pis)<span><i class="bi bi-receipt" aria-hidden="true"></i>{{ trans_choice('{1} one open PI|[2,*] :count open PIs', $client->open_pis, ['count' => $client->open_pis]) }}</span>@endif
                            @if($nextDue)<span class="{{ $nextDue->isPast() && ! $nextDue->isToday() ? 'text-danger' : '' }}"><i class="bi bi-alarm" aria-hidden="true"></i><span class="app-num">{{ $nextDue->format('Y-m-d') }}</span></span>@endif
                        </div>
                        @if($client->crm_stage_by === 'documents')
                            <div class="app-crm-card__why"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ __('From the documents') }} · <span class="app-num">{{ $client->crm_stage_since?->format('Y-m-d') }}</span></div>
                        @endif
                        @if($canMove && $typed)
                            <form method="POST" action="{{ route('crm.pipeline.move') }}" class="app-crm-card__move" data-move-form>
                                @csrf
                                <input type="hidden" name="party_id" value="{{ $client->id }}">
                                <label class="visually-hidden" for="mv-{{ $client->id }}">{{ __('Move :name to', ['name' => $client->name]) }}</label>
                                <select name="stage" id="mv-{{ $client->id }}" class="form-select form-select-sm" required>
                                    @unless(isset($typed[$client->crm_stage]))<option value="" selected disabled>{{ __('Move to…') }}</option>@endunless
                                    @foreach($typed as $tc => $t)<option value="{{ $tc }}" @selected($client->crm_stage === $tc)>{{ $t['label'] }}</option>@endforeach
                                </select>
                                <button class="btn btn-sm btn-light" title="{{ __('Move') }}" aria-label="{{ __('Move') }}"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="app-crm-col__empty">{{ __('None') }}</li>
                @endforelse
            </ul>
        </section>
    @endforeach
</div>
@endsection

@push('scripts')
<script>
    // Drag a card onto a stage a person sets: the card's own form moves it (the select works without this).
    (() => {
        let dragged = null;
        document.querySelectorAll('[data-card]').forEach((card) => {
            card.addEventListener('dragstart', (e) => { dragged = card; card.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; });
            card.addEventListener('dragend', () => { card.classList.remove('is-dragging'); dragged = null; document.querySelectorAll('.is-over').forEach((c) => c.classList.remove('is-over')); });
        });
        document.querySelectorAll('[data-drop]').forEach((col) => {
            col.addEventListener('dragover', (e) => { if (dragged) { e.preventDefault(); col.classList.add('is-over'); } });
            col.addEventListener('dragleave', () => col.classList.remove('is-over'));
            col.addEventListener('drop', (e) => {
                e.preventDefault();
                const form = dragged?.querySelector('[data-move-form]');
                if (! form) return;
                form.stage.value = col.dataset.drop;
                form.submit();
            });
        });
    })();
</script>
@endpush
