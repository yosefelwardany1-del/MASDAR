@extends('layouts.app')

@section('title', $order->number)

@use('App\Services\SupplierOrders\Quantity')
@use('Illuminate\Support\HtmlString')

@section('content')
@php
    $editable = $canEdit && $order->isOpen();
    $canAct = $canEdit && $order->status !== 'cancelled';
    $canSeePis = auth()->user()->can('proforma_invoices.view') && Route::has('proforma-invoices.show');
    $canSeePayments = auth()->user()->can('payments.view') && Route::has('payments.show');
    $total = $lines->reduce(fn ($sum, $l) => bcadd($sum, (string) $l->amount, 4), '0');
    $rtl = \App\Http\Middleware\SetUserLocale::isRtl();
    $comma = app()->getLocale() === 'ar' ? '، ' : ', ';

    // The steps at a glance: settled, late, and the one that comes next (the first not settled).
    $stepList = $steps->values();
    $stepCount = $stepList->count();
    $settledCount = $stepList->filter(fn ($s) => $states[$s->id]->isSettled())->count();
    $lateCount = $stepList->filter(fn ($s) => $states[$s->id]->state === 'late')->count();
    $next = $order->isOpen() ? $stepList->first(fn ($s) => ! $states[$s->id]->isSettled()) : null;

    // Goods: ordered, received and loaded cartons over the lines shown.
    $ordered = $lines->reduce(fn ($sum, $l) => bcadd($sum, (string) $l->cartons, 4), '0');
    $receivedAll = $lines->reduce(fn ($sum, $l) => bcadd($sum, (string) ($l->received_cartons ?? '0'), 4), '0');
    $loadedAll = $lines->reduce(fn ($sum, $l) => bcadd($sum, (string) ($l->loaded_cartons ?? '0'), 4), '0');
    $clientNames = $lines->map(fn ($l) => $l->piLine?->proformaInvoice?->client?->name)->filter()->unique()->join($comma);

    $kindLabels = ['payment' => __('Payment step'), 'receipt' => __('Completed by the goods receipt'), 'booking' => __('Completed by the booking'), 'loading' => __('Completed by the loading')];
    $kindIcons = ['payment' => 'cash-coin', 'receipt' => 'clipboard-check', 'booking' => 'calendar-check', 'loading' => 'box-seam'];

    // A step's state in words, with its date, for the stepper and the summary.
    // Dates are isolated left-to-right inside the sentence, so they read the same in Arabic.
    $num = fn (?string $date) => $date ? new HtmlString('<span class="app-num">'.e($date).'</span>') : null;
    $stateText = function ($state) use ($num) {
        $due = $state->dueOn?->format('Y-m-d');

        return match ($state->state) {
            'done' => ['done', 'check-circle', __('Done'), $num($state->latest?->happened_on?->format('Y-m-d'))],
            'skipped' => ['skip', 'dash-circle', __('Not applicable'), null],
            'late' => ['late', 'exclamation-triangle', __('Late'), $due ? new HtmlString(str_replace(':date', (string) $num($due), e(__('due :date')))) : null],
            'due' => ['due', 'clock', __('Due soon'), $num($due)],
            default => $due ? ['neutral', 'calendar', __('Due'), $num($due)] : ['neutral', 'circle', __('Not done yet'), null],
        };
    };
    // Which step form to show open again after a failed submit.
    $openStep = (int) old('step_id');
    $openAction = old('action');
    $dateFormFailed = old('delivery_date') !== null;
    $pageTitle = new HtmlString(e(__('Purchase order')).' <span class="app-ltr">'.e($order->number).'</span>');
@endphp

<x-page-header :title="$pageTitle"
               :back="route('supplier-orders.index')" :back-label="__('Purchase orders')"
               :subtitle="collect([$seeSuppliers ? $order->supplier?->name : null, $clientNames ?: null])->filter()->join(' · ') ?: null">
    <x-slot:meta>
        @include('supplier-orders._status', ['status' => $order->status])
        @if($lateCount > 0)
            <x-status tone="late">{{ trans_choice('{1} :count late step|[2,*] :count late steps', $lateCount, ['count' => $lateCount]) }}</x-status>
        @elseif($order->isOpen())
            <x-status tone="done">{{ __('On track') }}</x-status>
        @endif
    </x-slot:meta>
    <x-slot:actions>
        @if($order->isOpen() && auth()->user()->can('goods_receipts.view') && Route::has('goods-receipts.index'))
            <a href="{{ route('goods-receipts.index', ['order' => $order->id]) }}" class="btn btn-light"><i class="bi bi-clipboard-check" aria-hidden="true"></i>{{ __('Receive goods') }}</a>
        @endif
        @if(\App\Support\Access\Visibility::canSeeSupplierPrices(auth()->user()))
            <a href="{{ route('supplier-orders.contract', $order) }}" target="_blank" class="btn btn-light"><i class="bi bi-file-earmark-text" aria-hidden="true"></i>{{ __('Purchase contract') }}</a>
            @if($order->status !== 'cancelled')
                <a href="{{ route('documents.send.create', ['type' => 'purchase-contract', 'id' => $order->id]) }}" class="btn btn-light"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Send the contract') }}</a>
            @endif
            @can('claims.edit')
                <a href="{{ route('claims.create', ['order' => $order->id]) }}" class="btn btn-light"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i>{{ __('Open a claim') }}</a>
            @endcan
        @endif
        <a href="#steps" class="btn btn-light d-xl-none"><i class="bi bi-list-check" aria-hidden="true"></i>{{ __('Steps') }}</a>
    </x-slot:actions>
</x-page-header>

@include('supplier-orders._form-errors')

{{-- The order at a glance: how far along, what comes next, when it is due, the goods. --}}
<section class="app-card app-fu-summary mb-3" aria-label="{{ __('Summary') }}">
    <div class="app-fu-summary__item">
        <span class="app-fu-summary__label">{{ __('Steps done') }}</span>
        <span class="app-fu-summary__value"><span class="app-num">{{ $settledCount }}</span> <span class="app-fu-sub">/ <span class="app-num">{{ $stepCount }}</span></span></span>
        <x-progress :value="$settledCount" :max="max($stepCount, 1)" thin :tone="$lateCount ? 'late' : ($settledCount === $stepCount ? 'done' : 'primary')" />
    </div>
    <div class="app-fu-summary__item is-wide">
        <span class="app-fu-summary__label">{{ __('Next step') }}</span>
        @if($next)
            @php [$tone, $icon, $word, $when] = $stateText($states[$next->id]); @endphp
            <a href="#step-{{ $next->id }}" class="app-fu-summary__value is-text text-decoration-none">{{ $next->displayName() }}</a>
            <span><x-status :tone="$tone" :icon="$icon">{{ $word }}@if($when) · {{ $when }}@endif</x-status></span>
        @elseif($order->isOpen())
            <span class="app-fu-summary__value is-text">{{ __('Every step is settled') }}</span>
            @if($canEdit)<span class="app-fu-sub">{{ __('The order can be completed.') }}</span>@endif
        @else
            <span class="app-fu-summary__value is-text">{{ \App\Services\SupplierOrders\SupplierOrderService::statusLabel($order->status) }}</span>
        @endif
    </div>
    <div class="app-fu-summary__item">
        <span class="app-fu-summary__label">{{ __('Delivery date') }}</span>
        <span class="app-fu-summary__value app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
        @if($firstPromised && $firstPromised->toDateString() !== $order->delivery_date?->toDateString())
            <span><x-status tone="due" icon="calendar-x">{{ __('first promised :date', ['date' => $firstPromised->format('Y-m-d')]) }}</x-status></span>
        @else
            <span class="app-fu-sub">{{ __('Confirmed') }} <span class="app-num">{{ $order->confirmation_date?->format('Y-m-d') ?? '—' }}</span></span>
        @endif
    </div>
    <div class="app-fu-summary__item is-wide">
        <span class="app-fu-summary__label">{{ __('Goods') }}</span>
        <x-progress :value="$receivedAll" :max="$ordered" thin :label="__('Received cartons')" :caption="Quantity::trim($receivedAll).' / '.Quantity::trim($ordered)" :tone="bccomp($receivedAll, $ordered, 4) >= 0 && bccomp($ordered, '0', 4) > 0 ? 'done' : 'primary'" />
        <x-progress :value="$loadedAll" :max="$ordered" thin :label="__('Loaded cartons')" :caption="Quantity::trim($loadedAll).' / '.Quantity::trim($ordered)" :tone="bccomp($loadedAll, $ordered, 4) >= 0 && bccomp($ordered, '0', 4) > 0 ? 'done' : 'gold'" />
    </div>
</section>

<div class="row g-3 align-items-start">
    {{-- The steps (S2's columns) as a vertical stepper: state, dates and the actions beside each. --}}
    <div class="col-xl-8">
        <x-section :title="__('Steps')" icon="list-check" flush id="steps"
                   :subtitle="__(':done of :total settled', ['done' => $settledCount, 'total' => $stepCount])">
            <ol class="app-fu-tl">
                @foreach($stepList as $i => $step)
                    @php
                        $state = $states[$step->id];
                        $latest = $state->latest;
                        $isNext = $next?->id === $step->id;
                        $cls = match ($state->state) { 'done' => 'is-done', 'skipped' => 'is-skip', 'late' => 'is-late', 'due' => 'is-due', default => $isNext ? 'is-current' : 'is-todo' };
                        $dotIcon = match ($cls) { 'is-done' => 'check-lg', 'is-skip' => 'dash', 'is-late' => 'exclamation', 'is-due' => 'clock', 'is-current' => 'circle-fill', default => null };
                        [$tone, $icon, $word, $when] = $stateText($state);
                        $panel = $openStep === $step->id ? $openAction : null;
                    @endphp
                    <li class="app-fu-tl__item {{ $cls }}" id="step-{{ $step->id }}">
                        <span class="app-stepper__dot" aria-hidden="true">@if($dotIcon)<i class="bi bi-{{ $dotIcon }}"></i>@else{{ $i + 1 }}@endif</span>
                        <div class="app-fu-tl__body">
                            <div class="app-fu-tl__head">
                                <div>
                                    <span class="app-fu-tl__name"><span class="visually-hidden">{{ $i + 1 }}. </span>{{ $step->displayName() }}@if($step->requires_attachment)<i class="bi bi-paperclip" title="{{ __('Needs a document') }}" aria-label="{{ __('Needs a document') }}"></i>@endif</span>
                                    @if($isNext)<span class="app-fu-tl__flag">{{ __('Next') }}</span>@endif
                                    @if($step->kind !== 'manual')<div class="app-fu-tl__kind"><i class="bi bi-{{ $kindIcons[$step->kind] ?? 'gear' }}" aria-hidden="true"></i>{{ $kindLabels[$step->kind] ?? '' }}</div>@endif
                                </div>
                                <div><x-status :tone="$tone" :icon="$icon">{{ $word }}@if($when) · {{ $when }}@endif</x-status></div>
                                @if($canAct)
                                    <div class="app-fu-tl__actions">
                                        @if(! $latest || $latest->status !== 'done')
                                            <button type="button" class="btn btn-sm {{ $isNext ? 'btn-primary' : 'btn-outline-primary' }}"
                                                    data-bs-toggle="collapse" data-bs-target="#step-{{ $step->id }}-done" aria-controls="step-{{ $step->id }}-done" aria-expanded="{{ $panel === 'done' ? 'true' : 'false' }}">
                                                <i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Mark done') }}
                                            </button>
                                        @endif
                                        @if(! $latest || $latest->status !== 'skipped')
                                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#step-{{ $step->id }}-skip" aria-controls="step-{{ $step->id }}-skip" aria-expanded="{{ $panel === 'skip' ? 'true' : 'false' }}">{{ __('Not applicable') }}</button>
                                        @endif
                                        @if($latest && $latest->status !== 'reopened')
                                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#step-{{ $step->id }}-reopen" aria-controls="step-{{ $step->id }}-reopen" aria-expanded="{{ $panel === 'reopen' ? 'true' : 'false' }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reopen') }}</button>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if($latest)
                                <div class="app-fu-tl__meta">
                                    <span class="app-num">{{ $latest->happened_on->format('Y-m-d') }}</span> · {{ $latest->user?->name }}
                                    @if($latest->note)<span class="app-fu-sep" aria-hidden="true">—</span><span class="app-fu-note">{{ $latest->displayNote() }}</span>@endif
                                </div>
                            @endif

                            @if($canAct)
                                <div id="step-{{ $step->id }}-panels">
                                    @if(! $latest || $latest->status !== 'done')
                                        <div class="collapse app-fu-tl__panel @if($panel === 'done') show @endif" id="step-{{ $step->id }}-done" data-bs-parent="#step-{{ $step->id }}-panels">
                                            <form method="POST" action="{{ route('supplier-orders.steps.done', [$order, $step]) }}" enctype="multipart/form-data">
                                                @csrf
                                                <input type="hidden" name="step_id" value="{{ $step->id }}"><input type="hidden" name="action" value="done">
                                                <div class="row g-2">
                                                    <div class="col-sm-4">
                                                        <label class="form-label" for="s{{ $step->id }}-date">{{ __('Date') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                                        <input id="s{{ $step->id }}-date" type="date" name="happened_on" required max="{{ now()->toDateString() }}"
                                                               class="form-control form-control-sm @if($panel === 'done' && $errors->has('happened_on')) is-invalid @endif"
                                                               value="{{ $panel === 'done' ? old('happened_on', now()->toDateString()) : now()->toDateString() }}">
                                                        @if($panel === 'done')@error('happened_on')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                                    </div>
                                                    <div class="col-sm-8">
                                                        <label class="form-label" for="s{{ $step->id }}-note">{{ __('Note') }}</label>
                                                        <input id="s{{ $step->id }}-note" name="note" maxlength="2000" class="form-control form-control-sm @if($panel === 'done' && $errors->has('note')) is-invalid @endif" value="{{ $panel === 'done' ? old('note') : '' }}">
                                                        @if($panel === 'done')@error('note')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                                                    </div>
                                                    @if($step->kind === 'payment' && $payments->isNotEmpty())
                                                        <div class="col-sm-6">
                                                            <label class="form-label" for="s{{ $step->id }}-payment">{{ __('Payment') }}</label>
                                                            <select id="s{{ $step->id }}-payment" name="payment_id" class="form-select form-select-sm">
                                                                <option value="">—</option>
                                                                @foreach($payments as $payment)
                                                                    <option value="{{ $payment->id }}" @selected($panel === 'done' && (string) old('payment_id') === (string) $payment->id)>{{ $payment->number }} · {{ $payment->paid_on?->format('Y-m-d') }} · {{ Quantity::money($payment->amount) }} {{ $payment->currency }}</option>
                                                                @endforeach
                                                            </select>
                                                        </div>
                                                    @endif
                                                    <div class="{{ $step->kind === 'payment' && $payments->isNotEmpty() ? 'col-sm-6' : 'col-12' }}">
                                                        <label class="form-label" for="s{{ $step->id }}-files">{{ __('Files') }} @if($step->requires_attachment)<span class="app-fu-req" aria-hidden="true">*</span>@endif</label>
                                                        <input id="s{{ $step->id }}-files" type="file" name="files[]" multiple class="form-control form-control-sm @if($panel === 'done' && ($errors->has('files') || $errors->has('files.*'))) is-invalid @endif" @if($step->requires_attachment) required @endif>
                                                        @if($panel === 'done')
                                                            @error('files')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                            @error('files.*')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                                        @endif
                                                    </div>
                                                </div>
                                                @if($panel === 'done')@error('step')<div class="text-danger small mt-2">{{ $message }}</div>@enderror @endif
                                                <div class="app-fu-formactions mt-3">
                                                    <button class="btn btn-sm btn-primary"><i class="bi bi-check-lg" aria-hidden="true"></i>{{ __('Save') }}</button>
                                                    <button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#step-{{ $step->id }}-done">{{ __('Cancel') }}</button>
                                                </div>
                                            </form>
                                        </div>
                                    @endif
                                    @foreach(['skip' => ['supplier-orders.steps.skip', ! $latest || $latest->status !== 'skipped', __('Why does this step not apply?')], 'reopen' => ['supplier-orders.steps.reopen', $latest && $latest->status !== 'reopened', __('Why open this step again?')]] as $action => [$route, $show, $ask])
                                        @if($show)
                                            <div class="collapse app-fu-tl__panel @if($panel === $action) show @endif" id="step-{{ $step->id }}-{{ $action }}" data-bs-parent="#step-{{ $step->id }}-panels">
                                                <form method="POST" action="{{ route($route, [$order, $step]) }}">
                                                    @csrf
                                                    <input type="hidden" name="step_id" value="{{ $step->id }}"><input type="hidden" name="action" value="{{ $action }}">
                                                    <label class="form-label" for="s{{ $step->id }}-{{ $action }}-reason">{{ $ask }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                                    <div class="app-fu-inlineform">
                                                        <div>
                                                            <input id="s{{ $step->id }}-{{ $action }}-reason" name="reason" required maxlength="2000" class="form-control form-control-sm @if($panel === $action && $errors->has('reason')) is-invalid @endif" value="{{ $panel === $action ? old('reason') : '' }}" placeholder="{{ __('Reason') }}">
                                                            @if($panel === $action)@error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror @error('step')<div class="text-danger small mt-1">{{ $message }}</div>@enderror @endif
                                                        </div>
                                                        <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                                                        <button type="button" class="btn btn-sm btn-link" data-bs-toggle="collapse" data-bs-target="#step-{{ $step->id }}-{{ $action }}">{{ __('Cancel') }}</button>
                                                    </div>
                                                </form>
                                            </div>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-section>
    </div>

    <div class="col-xl-4 d-grid gap-3">
        {{-- Status: complete, cancel or reopen, and its history. --}}
        <x-section :title="__('Status')" icon="flag">
            <div class="d-flex align-items-center gap-2 mb-3">@include('supplier-orders._status', ['status' => $order->status])</div>
            @if($canEdit)
                <div class="app-fu-status-actions">
                    @if($order->isOpen())
                        <form method="POST" action="{{ route('supplier-orders.complete', $order) }}" onsubmit="return confirm(@js(__('Mark this order completed?')));">
                            @csrf
                            <button class="btn btn-sm btn-success"><i class="bi bi-check2-all" aria-hidden="true"></i>{{ __('Complete') }}</button>
                        </form>
                        <details class="app-fu-disclosure" @if($errors->has('reason') && ! $openStep && ! $dateFormFailed) open @endif>
                            <summary class="btn btn-sm btn-outline-danger">{{ __('Cancel the order') }}</summary>
                            <form method="POST" action="{{ route('supplier-orders.cancel', $order) }}" class="mt-2">
                                @csrf
                                <label class="form-label" for="cancel-reason">{{ __('Reason') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                                <textarea id="cancel-reason" name="reason" rows="2" class="form-control form-control-sm mb-2" required placeholder="{{ __('Reason') }}"></textarea>
                                <button class="btn btn-sm btn-danger">{{ __('Cancel the order') }}</button>
                            </form>
                        </details>
                    @else
                        <form method="POST" action="{{ route('supplier-orders.reopen', $order) }}" class="w-100">
                            @csrf
                            <label class="form-label" for="reopen-reason">{{ __('Reason (optional)') }}</label>
                            <div class="app-fu-inlineform">
                                <input id="reopen-reason" name="reason" class="form-control form-control-sm" placeholder="{{ __('Reason (optional)') }}">
                                <button class="btn btn-sm btn-light"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('Reopen') }}</button>
                            </div>
                        </form>
                    @endif
                </div>
            @endif
            @if($statusHistory->isNotEmpty())
                <ul class="app-fu-history mt-3">
                    @foreach($statusHistory as $log)
                        <li>
                            <div class="app-fu-history__main">{{ ['created' => __('Created'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'), 'reopened' => __('Reopened')][$log->action] ?? $log->action }}</div>
                            <div class="app-fu-history__meta"><span class="app-num">{{ $log->created_at?->format('Y-m-d H:i') }}</span> · {{ $log->user?->name ?? '—' }}</div>
                            @if(! empty($log->new_values['reason']))<div class="app-fu-history__note">{{ $log->new_values['reason'] }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section>

        {{-- The order's own details. --}}
        <x-section :title="__('Supplier order')" icon="file-earmark-text">
            @php
                $supplierValue = '—';
                if ($seeSuppliers && $order->supplier) {
                    $supplierValue = auth()->user()->can('suppliers.view') && Route::has('suppliers.show')
                        ? new HtmlString('<a href="'.e(route('suppliers.show', $order->supplier_id)).'">'.e($order->supplier->name).'</a>')
                        : $order->supplier->name;
                }
                $details = [__('Factory / supplier') => $supplierValue];
                $details[__('Confirmation date')] = new HtmlString('<span class="app-num">'.e($order->confirmation_date?->format('Y-m-d') ?? '—').'</span>');
                $details[__('Currency')] = new HtmlString('<span class="app-ltr">'.e($order->currency).'</span>');
                $details[__('Deposit %')] = new HtmlString('<span class="app-num">'.e($order->deposit_percent !== null ? Quantity::trim($order->deposit_percent).'%' : '—').'</span>');
                if ($seeSuppliers) {
                    $details[__('Order amount')] = new HtmlString('<span class="app-num">'.e(Quantity::money($total)).'</span> <span class="app-ltr">'.e($order->currency).'</span>');
                }
                $details[__('Payment terms')] = $order->payment_terms ?: '—';
                $details[__('Merchandiser')] = $order->merchandiser?->name ?? '—';
                $details[__('Created by')] = new HtmlString(e($order->creator?->name).' · <span class="app-num">'.e($order->created_at?->format('Y-m-d')).'</span>');
            @endphp
            <x-detail-list :items="$details" :cols="2" />
            @if($order->notes)
                <div class="mt-3">
                    <div class="app-fu-summary__label mb-1">{{ __('Notes') }}</div>
                    <div style="white-space:pre-line">{{ $order->notes }}</div>
                </div>
            @endif

            @if($editable)
                <form method="POST" action="{{ route('supplier-orders.merchandiser', $order) }}" class="mt-3 pt-3 border-top">
                    @csrf @method('PUT')
                    <label class="form-label" for="merchandiser_id">{{ __('Merchandiser') }}</label>
                    <div class="app-fu-inlineform">
                        <select id="merchandiser_id" name="merchandiser_id" class="form-select form-select-sm @error('merchandiser_id') is-invalid @enderror">
                            <option value="">—</option>
                            @foreach($merchandisers as $m)
                                <option value="{{ $m->id }}" @selected((int) old('merchandiser_id', $order->merchandiser_id) === $m->id)>{{ $m->name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-light">{{ __('Save') }}</button>
                    </div>
                    @error('merchandiser_id')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </form>

                <details class="app-fu-disclosure mt-3" @if($errors->hasAny(['currency', 'confirmation_date', 'deposit_percent', 'payment_terms', 'notes'])) open @endif>
                    <summary class="btn btn-sm btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit the order') }}</summary>
                    <form method="POST" action="{{ route('supplier-orders.update', $order) }}" class="row g-2 mt-2">
                        @csrf @method('PUT')
                        <div class="col-sm-6">
                            <label class="form-label" for="confirmation_date">{{ __('Confirmation date') }}</label>
                            <input id="confirmation_date" type="date" name="confirmation_date" class="form-control form-control-sm @error('confirmation_date') is-invalid @enderror" value="{{ old('confirmation_date', $order->confirmation_date?->toDateString()) }}">
                            @error('confirmation_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="deposit_percent">{{ __('Deposit %') }}</label>
                            <input id="deposit_percent" type="number" step="0.01" min="0" max="100" name="deposit_percent" class="form-control form-control-sm @error('deposit_percent') is-invalid @enderror" value="{{ old('deposit_percent', $order->deposit_percent) }}" dir="ltr">
                            @error('deposit_percent')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="currency">{{ __('Currency') }}</label>
                            <select id="currency" name="currency" class="form-select form-select-sm @error('currency') is-invalid @enderror">
                                @foreach($currencies as $code => $label)
                                    <option value="{{ $code }}" @selected(old('currency', $order->currency) === $code)>{{ $code }} — {{ $label }}</option>
                                @endforeach
                            </select>
                            @error('currency')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="payment_terms">{{ __('Payment terms') }}</label>
                            <input id="payment_terms" name="payment_terms" maxlength="255" class="form-control form-control-sm @error('payment_terms') is-invalid @enderror" value="{{ old('payment_terms', $order->payment_terms) }}">
                            @error('payment_terms')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea id="notes" name="notes" rows="2" class="form-control form-control-sm @error('notes') is-invalid @enderror">{{ old('notes', $order->notes) }}</textarea>
                            @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12"><button class="btn btn-sm btn-primary">{{ __('Save') }}</button></div>
                    </form>
                </details>
            @endif
        </x-section>

        {{-- The promised delivery date and its history (never overwritten without a reason). --}}
        <x-section :title="__('Delivery date history')" icon="calendar-event">
            <div class="d-flex flex-wrap align-items-baseline gap-2 mb-1">
                <span class="app-fu-bigdate app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                @if($firstPromised && $firstPromised->toDateString() !== $order->delivery_date?->toDateString())
                    <x-status tone="due" icon="calendar-x">{{ __('first promised :date', ['date' => $firstPromised->format('Y-m-d')]) }}</x-status>
                @endif
            </div>
            @if($editable)
                <form method="POST" action="{{ route('supplier-orders.delivery-date', $order) }}" class="row g-2 mt-2">
                    @csrf
                    <div class="col-sm-5 col-xl-12 col-xxl-5">
                        <label class="form-label" for="new_delivery_date">{{ __('New delivery date') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <input id="new_delivery_date" type="date" name="delivery_date" required class="form-control form-control-sm @if($dateFormFailed && $errors->has('delivery_date')) is-invalid @endif" value="{{ old('delivery_date') }}">
                        @if($dateFormFailed)@error('delivery_date')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="col-sm-7 col-xl-12 col-xxl-7">
                        <label class="form-label" for="date_reason">{{ __('Reason') }} <span class="app-fu-req" aria-hidden="true">*</span></label>
                        <input id="date_reason" name="reason" required maxlength="2000" class="form-control form-control-sm @if($dateFormFailed && $errors->has('reason')) is-invalid @endif" value="{{ $dateFormFailed ? old('reason') : '' }}" placeholder="{{ __('Why the supplier moved the date') }}">
                        @if($dateFormFailed)@error('reason')<div class="invalid-feedback">{{ $message }}</div>@enderror @endif
                    </div>
                    <div class="col-12"><button class="btn btn-sm btn-primary"><i class="bi bi-calendar-check" aria-hidden="true"></i>{{ __('Change date') }}</button></div>
                </form>
            @endif
            @if($order->dateChanges->isEmpty())
                <p class="app-fu-sub mt-3 mb-0"><i class="bi bi-check-circle text-success" aria-hidden="true"></i> {{ __('The delivery date has not moved.') }}</p>
            @else
                <ul class="app-fu-history mt-3">
                    @foreach($order->dateChanges->reverse() as $change)
                        <li>
                            <div class="app-fu-history__main">
                                <span class="app-num">{{ $change->old_date?->format('Y-m-d') ?? '—' }}</span>
                                <i class="bi {{ $rtl ? 'bi-arrow-left' : 'bi-arrow-right' }} text-muted" aria-label="{{ __('To') }}"></i>
                                <span class="app-num">{{ $change->new_date?->format('Y-m-d') ?? '—' }}</span>
                            </div>
                            <div class="app-fu-history__note">{{ $change->reason }}</div>
                            <div class="app-fu-history__meta"><span class="app-num">{{ $change->created_at?->format('Y-m-d H:i') }}</span> · {{ $change->user?->name }}</div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-section>
    </div>
</div>

{{-- Lines: each is a PI line at the supplier's price. --}}
<x-section :title="__('Lines')" icon="list-ul" flush class="mt-3 app-fu-wraphead" :subtitle="trans_choice('{0} No lines|{1} :count line|[2,*] :count lines', $lines->count(), ['count' => $lines->count()])">
    @if($editable && $seeSuppliers && $orderablePis->isNotEmpty())
        <x-slot:actions>
            <form method="GET" action="{{ route('supplier-orders.lines.create', $order) }}" class="d-flex gap-2">
                <select name="pi" class="form-select form-select-sm" required aria-label="{{ __('Add lines from a PI…') }}">
                    <option value="">{{ __('Add lines from a PI…') }}</option>
                    @foreach($orderablePis as $p)
                        <option value="{{ $p->id }}">{{ $p->reference() }} — {{ $p->client?->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-light text-nowrap"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add lines') }}</button>
            </form>
        </x-slot:actions>
    @endif
    <div class="table-responsive app-board">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('PI') }}</th><th>{{ __('Client') }}</th><th><span class="visually-hidden">{{ __('Photo') }}</span></th><th>{{ __('Item') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                @if($seeSuppliers)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Amount') }}</th>@endif
                <th class="text-end">{{ __('Received cartons') }}</th><th class="text-end">{{ __('Loaded cartons') }}</th>
            </tr></thead>
            <tbody>
            @forelse($lines as $line)
                @php
                    $pi = $line->piLine?->proformaInvoice;
                    $received = (string) ($line->received_cartons ?? '0');
                    $loaded = (string) ($line->loaded_cartons ?? '0');
                    $allIn = bccomp($received, (string) $line->cartons, 4) >= 0;
                    $allLoaded = bccomp($loaded, (string) $line->cartons, 4) >= 0;
                @endphp
                <tr @class(['app-fu-row--released' => $line->released_at])>
                    <td>
                        @if($pi && $canSeePis)
                            <a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr">{{ $pi->reference() }}</a>
                        @else
                            <span class="app-ltr">{{ $pi?->reference() }}</span>
                        @endif
                        <span class="app-fu-sub app-num">#{{ $line->piLine?->line_no }}</span>
                    </td>
                    <td>{{ $pi?->client?->name }}</td>
                    <td>@include('supplier-orders._photo', ['product' => $line->piLine?->product])</td>
                    <td style="white-space:normal; min-inline-size:14rem">
                        <div class="fw-semibold">{{ $line->piLine?->product?->name }}</div>
                        <div class="app-fu-sub">{{ $line->piLine?->description }}</div>
                        @if($line->released_at)
                            <x-status tone="skip" icon="box-arrow-right" class="mt-1" :title="__('Released on :date: the PI line can be ordered from another factory.', ['date' => $line->released_at->format('Y-m-d')])">{{ __('Released') }} · <span class="app-num">{{ $line->released_at->format('Y-m-d') }}</span></x-status>
                        @endif
                    </td>
                    <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->total_qty) }} {{ $line->piLine?->unit }}</td>
                    @if($seeSuppliers)
                        <td class="app-num text-end">{{ Quantity::trim($line->unit_price) }}</td>
                        <td class="app-num text-end">{{ Quantity::money($line->amount) }}</td>
                    @endif
                    <td class="app-num text-end {{ $allIn ? 'text-success fw-semibold' : '' }}">@if($allIn)<i class="bi bi-check-circle me-1" aria-hidden="true"></i>@endif{{ Quantity::trim($received) }}</td>
                    <td class="app-num text-end {{ $allLoaded ? 'text-success fw-semibold' : '' }}">@if($allLoaded)<i class="bi bi-check-circle me-1" aria-hidden="true"></i>@endif{{ Quantity::trim($loaded) }}</td>
                </tr>
            @empty
                <tr><td colspan="{{ $seeSuppliers ? 10 : 8 }}" class="app-empty">{{ __('No lines.') }}</td></tr>
            @endforelse
            </tbody>
            @if($seeSuppliers && $lines->isNotEmpty())
                <tfoot><tr>
                    <th colspan="7" class="text-end">{{ __('Total') }}</th>
                    <th class="app-num text-end">{{ Quantity::money($total) }}</th>
                    <th colspan="2"></th>
                </tr></tfoot>
            @endif
        </table>
    </div>
</x-section>

{{-- Every step event, newest first: the log is append-only, so this is the full story. --}}
<x-section :title="__('Step history')" icon="clock-history" flush class="mt-3">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('Recorded') }}</th><th>{{ __('Step') }}</th><th>{{ __('Event') }}</th><th>{{ __('Date') }}</th><th>{{ __('Note') }}</th><th>{{ __('Payment') }}</th><th>{{ __('By') }}</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr>
                    <td class="app-num small text-muted app-fu-nowrap">{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="app-fu-wrap" style="min-inline-size:10rem">{{ $event->step?->displayName() }}</td>
                    <td>
                        @if($event->status === 'done')
                            <x-status tone="done">{{ __('Done') }}</x-status>
                        @elseif($event->status === 'skipped')
                            <x-status tone="skip">{{ __('Not applicable') }}</x-status>
                        @elseif($event->status === 'reopened')
                            <x-status icon="arrow-counterclockwise">{{ __('Reopened') }}</x-status>
                        @else
                            {{ $event->status }}
                        @endif
                    </td>
                    <td class="app-num app-fu-nowrap">{{ $event->happened_on?->format('Y-m-d') }}</td>
                    <td class="small" style="white-space:normal">{{ $event->displayNote() }}</td>
                    <td>
                        @if($event->payment)
                            @if($canSeePayments)
                                <a href="{{ route('payments.show', $event->payment) }}" class="app-ltr">{{ $event->payment->number }}</a>
                            @else
                                <span class="app-ltr">{{ $event->payment->number }}</span>
                            @endif
                        @endif
                    </td>
                    <td>{{ $event->user?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="app-empty">{{ __('No step recorded yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>

{{-- The documents (stamped contract, sample photos, artwork) carry supplier details and prices. --}}
@if($seeSuppliers)
    @include('partials.attachments', ['attachable' => $order, 'type' => 'supplier-orders'])
@endif
@endsection

@if($errors->any())
    @push('scripts')
    <script>
        // After a failed submit: the open form's first invalid field, else the summary.
        document.addEventListener('DOMContentLoaded', () => {
            const field = document.querySelector('.app-fu-tl__panel.show .is-invalid, form .is-invalid');
            (field || document.getElementById('form-errors'))?.focus();
        });
    </script>
    @endpush
@endif
