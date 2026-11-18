{{-- Contacts across parties: $interactions, $channelIcons, $channels, $compact. --}}
@if($interactions->isEmpty())
    <x-empty icon="chat-left-text" :title="__('Nothing recorded yet.')" :text="__('Each call, WhatsApp or WeChat message and visit is kept here, newest first.')" />
@else
    <ol class="app-timeline app-crm-timeline">
        @foreach($interactions as $i)
            <li class="app-timeline__item">
                <span class="app-timeline__icon app-timeline__icon--{{ $i->channel }}" aria-hidden="true"><i class="bi bi-{{ $channelIcons[$i->channel] ?? 'chat-dots' }}"></i></span>
                <div class="app-timeline__body">
                    <div class="app-timeline__meta">
                        @if($i->party)<span class="fw-semibold">@include('crm._party-link', ['party' => $i->party])</span>@endif
                        <span class="app-timeline__channel">{{ $channels[$i->channel] ?? $i->channel }}</span>
                        <time class="app-num" dir="ltr" datetime="{{ $i->happened_at->toIso8601String() }}">{{ $i->happened_at->format('Y-m-d H:i') }}</time>
                        @if($i->user)<span>· <bdi>{{ $i->user->name }}</bdi></span>@endif
                    </div>
                    <div class="app-timeline__text {{ ! empty($compact) ? 'app-crm-timeline__clamp' : '' }}" dir="auto">{!! nl2br(e($i->summary)) !!}</div>
                    @if($i->next_step)
                        <div class="app-timeline__next"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><span><span class="fw-semibold">{{ __('Next step') }}:</span> <bdi>{{ $i->next_step }}</bdi></span></div>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
