@extends('layouts.app')

@section('title', __('Send :document', ['document' => $reference]))

@php
    $params = ['type' => $type, 'id' => $model->getKey()] + ($client ? ['client' => $client] : []);
    $emails = $contacts->filter(fn ($c) => filled($c->email));
    $phones = $contacts->filter(fn ($c) => filled($c->whatsapp) || filled($c->phone));
    $blocked = $notReady || ! $maySend || ! $pdfReady;
@endphp

@section('content')
<x-page-header :title="__('Send :document', ['document' => $reference])" :subtitle="$party?->name" :back="$back" :back-label="__('Back')">
    <x-slot:actions>
        @if($pdfReady)
            <a href="{{ route('documents.pdf', $params) }}" target="_blank" class="btn btn-light"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>{{ __('Download the PDF') }}</a>
        @endif
    </x-slot:actions>
</x-page-header>

@foreach(array_filter([$notReady, $maySend ? null : __('You may see this document but not send it.'), $pdfReady ? null : $pdfProblem]) as $why)
    <div class="alert alert-warning d-flex gap-2"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>{{ $why }}</div></div>
@endforeach

@unless($blocked)
<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <form method="POST" action="{{ route('documents.send.store', $params) }}" class="app-card p-3 app-form">
            @csrf
            <input type="hidden" name="channel" value="email">
            <h2 class="h6 mb-3"><i class="bi bi-envelope" aria-hidden="true"></i> {{ __('By email') }}</h2>
            @if($emailReady)
                <p class="app-note mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ $emailReady }}</span></p>
            @else
                @if($emails->isNotEmpty())
                    <fieldset class="mb-2">
                        <legend class="form-label">{{ __('To') }}</legend>
                        @foreach($emails as $c)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="to[]" value="{{ $c->email }}" id="to{{ $c->id }}" @checked($loop->first)>
                                <label class="form-check-label" for="to{{ $c->id }}">{{ $c->name }} <span class="text-muted app-ltr">&lt;{{ $c->email }}&gt;</span></label>
                            </div>
                        @endforeach
                    </fieldset>
                @endif
                <label class="form-label" for="to_other">{{ $emails->isNotEmpty() ? __('Other addresses') : __('To') }}</label>
                <input id="to_other" name="to_other" value="{{ old('to_other') }}" class="form-control mb-2 @error('to') is-invalid @enderror" dir="ltr" placeholder="name@example.com">
                @error('to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <label class="form-label" for="cc">{{ __('Copy to') }}</label>
                <input id="cc" name="cc" value="{{ old('cc', auth()->user()->email) }}" class="form-control mb-2 @error('cc') is-invalid @enderror" dir="ltr">
                @error('cc')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <label class="form-label" for="subject">{{ __('Subject') }}</label>
                <input id="subject" name="subject" value="{{ old('subject', $subject) }}" class="form-control mb-2 @error('subject') is-invalid @enderror" dir="ltr">
                <label class="form-label" for="message">{{ __('Message') }}</label>
                <textarea id="message" name="message" rows="7" class="form-control mb-2" dir="auto">{{ old('message', $message) }}</textarea>
                <div class="form-text mb-2">{{ __('The PDF goes attached. Replies come to your own address.') }}</div>
                <button class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send the email') }}</button>
            @endif
        </form>
    </div>
    <div class="col-lg-5">
        <form method="POST" action="{{ route('documents.send.store', $params) }}" class="app-card p-3 app-form">
            @csrf
            <input type="hidden" name="channel" value="whatsapp">
            <h2 class="h6 mb-3"><i class="bi bi-whatsapp" aria-hidden="true"></i> {{ __('By WhatsApp') }}</h2>
            @if($whatsappReady)
                <p class="app-note mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ $whatsappReady }}</span></p>
            @else
                <label class="form-label" for="phone">{{ __('WhatsApp number') }}</label>
                <input id="phone" name="phone" list="phones" value="{{ old('phone', $phones->first()?->whatsapp ?: $phones->first()?->phone) }}" class="form-control mb-2 @error('phone') is-invalid @enderror" dir="ltr" required>
                <datalist id="phones">@foreach($phones as $c)<option value="{{ $c->whatsapp ?: $c->phone }}">{{ $c->name }}</option>@endforeach</datalist>
                @error('phone')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="form-text mb-2">{{ __('Sent as MASDAR\'s approved document template, with the PDF.') }}</div>
                <button class="btn btn-primary"><i class="bi bi-whatsapp" aria-hidden="true"></i>{{ __('Send on WhatsApp') }}</button>
            @endif
        </form>
    </div>
</div>
@endunless

<x-section :title="__('Sent so far')" icon="clock-history" flush>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th scope="col">{{ __('When') }}</th><th scope="col">{{ __('How') }}</th><th scope="col">{{ __('To') }}</th><th scope="col">{{ __('By') }}</th><th scope="col">{{ __('Result') }}</th></tr></thead>
            <tbody>
            @forelse($sends as $s)
                <tr>
                    <td><span class="app-num">{{ $s->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</span></td>
                    <td>{{ $s->channel === 'email' ? __('Email') : __('WhatsApp') }}</td>
                    <td><span class="app-ltr small">{{ $s->recipients }}</span></td>
                    <td>{{ $s->user?->name }}</td>
                    <td>@if($s->status === 'sent')<x-status tone="done">{{ __('Sent') }}</x-status>@else<x-status tone="late" icon="exclamation-triangle">{{ __('Not sent') }}</x-status><div class="small text-muted">{{ $s->error }}</div>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><div class="app-panel-empty"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Not sent from the system yet.') }}</div></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
@endsection
