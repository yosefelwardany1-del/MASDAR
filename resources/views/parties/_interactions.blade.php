{{-- The CRM log of a party (spec §3.7), newest first. Append-only: nothing here edits or deletes
     an interaction; recording one needs crm.edit. Errors are in the "interaction" bag. --}}
@php
    $canCrm = auth()->user()->can('crm.edit');
    $iErrors = $errors->getBag('interaction');
    $useOld = $iErrors->any();
    $channelIcons = ['call' => 'telephone', 'whatsapp' => 'whatsapp', 'wechat' => 'wechat', 'email' => 'envelope', 'meeting' => 'people', 'factory_visit' => 'building'];
@endphp
<section class="app-card h-100" id="crm" aria-labelledby="crm-head">
    <div class="app-card__head border-bottom" id="crm-head">
        <i class="bi bi-chat-left-text" aria-hidden="true"></i>{{ __('CRM log') }}
        <span class="app-ptabs__count app-num">{{ $interactions->count() }}</span>
    </div>

    @if($canCrm)
        <details class="app-composer" @if($useOld || $interactions->isEmpty()) open @endif>
            <summary class="app-composer__summary"><i class="bi bi-journal-plus" aria-hidden="true"></i>{{ __('Record interaction') }}</summary>
            <form method="POST" action="{{ route('crm.interactions.store', $party) }}" class="app-composer__body">
                @csrf
                <div class="row g-3">
                    <div class="col-sm-6">
                        @include('parties._select', ['name' => 'channel', 'label' => __('Channel'), 'options' => $channels, 'value' => $useOld ? old('channel') : null, 'required' => true, 'bag' => 'interaction', 'id' => 'i-channel'])
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="i-when">{{ __('When') }} <span class="text-danger" aria-hidden="true">*</span></label>
                        <input type="datetime-local" name="happened_at" id="i-when" class="form-control {{ $iErrors->has('happened_at') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('happened_at') : now()->format('Y-m-d\TH:i') }}" required>
                        @if($iErrors->has('happened_at'))<div class="invalid-feedback">{{ $iErrors->first('happened_at') }}</div>@endif
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="i-summary">{{ __('Summary') }} <span class="text-danger" aria-hidden="true">*</span></label>
                        <textarea name="summary" id="i-summary" rows="3" class="form-control {{ $iErrors->has('summary') ? 'is-invalid' : '' }}" required>{{ $useOld ? old('summary') : '' }}</textarea>
                        @if($iErrors->has('summary'))<div class="invalid-feedback">{{ $iErrors->first('summary') }}</div>@endif
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label" for="i-next">{{ __('Next step') }}</label>
                        <input type="text" name="next_step" id="i-next" class="form-control {{ $iErrors->has('next_step') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('next_step') : '' }}" maxlength="2000" aria-describedby="i-next-help">
                        @if($iErrors->has('next_step'))<div class="invalid-feedback">{{ $iErrors->first('next_step') }}</div>@endif
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="i-next-due">{{ __('Next step due') }}</label>
                        <input type="date" name="next_step_due" id="i-next-due" class="form-control {{ $iErrors->has('next_step_due') ? 'is-invalid' : '' }}" value="{{ $useOld ? old('next_step_due') : '' }}" aria-describedby="i-next-help">
                        @if($iErrors->has('next_step_due'))<div class="invalid-feedback">{{ $iErrors->first('next_step_due') }}</div>@endif
                    </div>
                    <div class="col-12 form-text mt-1" id="i-next-help">{{ __('A next step with a due date also becomes a task for you.') }}</div>
                </div>
                <button class="btn btn-primary mt-3"><i class="bi bi-journal-plus" aria-hidden="true"></i>{{ __('Record interaction') }}</button>
            </form>
        </details>
    @endif

    @if($interactions->isEmpty())
        <x-empty icon="chat-left-text" :title="__('Nothing recorded yet.')" :text="__('Each call, WhatsApp or WeChat message and visit is kept here, newest first.')" />
    @else
        <ol class="app-timeline">
            @foreach($interactions as $i)
                <li class="app-timeline__item">
                    <span class="app-timeline__icon app-timeline__icon--{{ $i->channel }}" aria-hidden="true"><i class="bi bi-{{ $channelIcons[$i->channel] ?? 'chat-dots' }}"></i></span>
                    <div class="app-timeline__body">
                        <div class="app-timeline__meta">
                            <span class="app-timeline__channel">{{ $labels['interaction_channel'][$i->channel] ?? $i->channel }}</span>
                            <time class="app-num" dir="ltr" datetime="{{ $i->happened_at->toIso8601String() }}">{{ $i->happened_at->format('Y-m-d H:i') }}</time>
                            @if($i->user)<span>· <bdi>{{ $i->user->name }}</bdi></span>@endif
                        </div>
                        <div class="app-timeline__text" dir="auto">{!! nl2br(e($i->summary)) !!}</div>
                        @if($i->next_step)
                            <div class="app-timeline__next"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><span><span class="fw-semibold">{{ __('Next step') }}:</span> <bdi>{{ $i->next_step }}</bdi></span></div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
