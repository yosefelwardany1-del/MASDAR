@extends('layouts.app')

@section('title', $order->number)

@use('App\Services\SupplierOrders\Quantity')

@section('content')
@php
    $editable = $canEdit && $order->isOpen();
    $canSeePis = auth()->user()->can('proforma_invoices.view') && Route::has('proforma-invoices.show');
    $canSeePayments = auth()->user()->can('payments.view') && Route::has('payments.show');
    $total = $lines->reduce(fn ($sum, $l) => bcadd($sum, (string) $l->amount, 4), '0');
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex align-items-center gap-2">
        <h1 class="h4 mb-0"><span class="app-ltr">{{ $order->number }}</span></h1>
        @include('supplier-orders._status', ['status' => $order->status])
    </div>
    <a href="{{ route('supplier-orders.index') }}" class="btn btn-light"><i class="bi bi-kanban me-1"></i>{{ __('Back to the board') }}</a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card p-3 h-100">
            <h2 class="h6 mb-3">{{ __('Supplier order') }}</h2>
            <dl class="row mb-0">
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Factory / supplier') }}</dt>
                <dd class="col-sm-8">
                    @if($seeSuppliers)
                        @if(auth()->user()->can('suppliers.view') && Route::has('suppliers.show'))
                            <a href="{{ route('suppliers.show', $order->supplier_id) }}">{{ $order->supplier?->name }}</a>
                        @else
                            {{ $order->supplier?->name }}
                        @endif
                    @else
                        —
                    @endif
                </dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Confirmation date') }}</dt>
                <dd class="col-sm-8 app-num">{{ $order->confirmation_date?->format('Y-m-d') ?? '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Delivery date') }}</dt>
                <dd class="col-sm-8">
                    <span class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                    @if($firstPromised && $firstPromised->toDateString() !== $order->delivery_date?->toDateString())
                        <span class="text-warning small ms-2"><i class="bi bi-calendar-x me-1"></i>{{ __('first promised :date', ['date' => $firstPromised->format('Y-m-d')]) }}</span>
                    @endif
                </dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Currency') }}</dt>
                <dd class="col-sm-8 app-ltr">{{ $order->currency }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Deposit %') }}</dt>
                <dd class="col-sm-8 app-num">{{ $order->deposit_percent !== null ? Quantity::trim($order->deposit_percent).'%' : '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Payment terms') }}</dt>
                <dd class="col-sm-8">{{ $order->payment_terms ?: '—' }}</dd>
                @if($seeSuppliers)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Order amount') }}</dt>
                    <dd class="col-sm-8"><span class="app-num fw-semibold">{{ Quantity::money($total) }}</span> <span class="app-ltr">{{ $order->currency }}</span></dd>
                @endif
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Merchandiser') }}</dt>
                <dd class="col-sm-8">{{ $order->merchandiser?->name ?? '—' }}</dd>
                <dt class="col-sm-4 text-muted fw-normal">{{ __('Created by') }}</dt>
                <dd class="col-sm-8">{{ $order->creator?->name }} · <span class="app-num">{{ $order->created_at?->format('Y-m-d') }}</span></dd>
                @if($order->notes)
                    <dt class="col-sm-4 text-muted fw-normal">{{ __('Notes') }}</dt>
                    <dd class="col-sm-8" style="white-space:pre-line">{{ $order->notes }}</dd>
                @endif
            </dl>

            @if($editable)
                <details class="mt-3" @if($errors->hasAny(['currency', 'confirmation_date', 'deposit_percent', 'payment_terms', 'notes'])) open @endif>
                    <summary class="btn btn-sm btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit the order') }}</summary>
                    <form method="POST" action="{{ route('supplier-orders.update', $order) }}" class="row g-3 mt-1">
                        @csrf @method('PUT')
                        <div class="col-md-4">
                            <label class="form-label" for="confirmation_date">{{ __('Confirmation date') }}</label>
                            <input id="confirmation_date" type="date" name="confirmation_date" class="form-control @error('confirmation_date') is-invalid @enderror" value="{{ old('confirmation_date', $order->confirmation_date?->toDateString()) }}">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="currency">{{ __('Currency') }}</label>
                            <select id="currency" name="currency" class="form-select @error('currency') is-invalid @enderror">
                                @foreach($currencies as $code => $label)
                                    <option value="{{ $code }}" @selected(old('currency', $order->currency) === $code)>{{ $code }} — {{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="deposit_percent">{{ __('Deposit %') }}</label>
                            <input id="deposit_percent" type="number" step="0.01" min="0" max="100" name="deposit_percent" class="form-control @error('deposit_percent') is-invalid @enderror" value="{{ old('deposit_percent', $order->deposit_percent) }}">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="payment_terms">{{ __('Payment terms') }}</label>
                            <input id="payment_terms" name="payment_terms" maxlength="255" class="form-control @error('payment_terms') is-invalid @enderror" value="{{ old('payment_terms', $order->payment_terms) }}">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label" for="notes">{{ __('Notes') }}</label>
                            <textarea id="notes" name="notes" rows="2" class="form-control @error('notes') is-invalid @enderror">{{ old('notes', $order->notes) }}</textarea>
                        </div>
                        <div class="col-12"><button class="btn btn-primary">{{ __('Save') }}</button></div>
                    </form>
                </details>
            @endif
        </div>
    </div>

    <div class="col-lg-4">
        <div class="app-card p-3 mb-3">
            <h2 class="h6 mb-3">{{ __('Status') }}</h2>
            <div class="mb-2">@include('supplier-orders._status', ['status' => $order->status])</div>
            @if($canEdit)
                @if($order->isOpen())
                    <form method="POST" action="{{ route('supplier-orders.complete', $order) }}" class="mb-2" onsubmit="return confirm(@js(__('Mark this order completed?')));">
                        @csrf
                        <button class="btn btn-sm btn-success"><i class="bi bi-check2-all me-1"></i>{{ __('Complete') }}</button>
                    </form>
                    <details>
                        <summary class="btn btn-sm btn-light text-danger">{{ __('Cancel the order') }}</summary>
                        <form method="POST" action="{{ route('supplier-orders.cancel', $order) }}" class="mt-2">
                            @csrf
                            <textarea name="reason" rows="2" class="form-control form-control-sm mb-2" required placeholder="{{ __('Reason') }}"></textarea>
                            <button class="btn btn-sm btn-danger">{{ __('Cancel the order') }}</button>
                        </form>
                    </details>
                @else
                    <form method="POST" action="{{ route('supplier-orders.reopen', $order) }}">
                        @csrf
                        <input name="reason" class="form-control form-control-sm mb-2" placeholder="{{ __('Reason (optional)') }}">
                        <button class="btn btn-sm btn-light"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reopen') }}</button>
                    </form>
                @endif
            @endif
            @if($statusHistory->isNotEmpty())
                <ul class="list-unstyled small text-muted mt-3 mb-0">
                    @foreach($statusHistory as $log)
                        <li class="py-1 border-top">
                            <span class="app-num">{{ $log->created_at?->format('Y-m-d H:i') }}</span> ·
                            {{ ['created' => __('Created'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'), 'reopened' => __('Reopened')][$log->action] ?? $log->action }}
                            · {{ $log->user?->name ?? '—' }}
                            @if(! empty($log->new_values['reason']))<div>{{ $log->new_values['reason'] }}</div>@endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        @if($editable)
            <div class="app-card p-3">
                <h2 class="h6 mb-3">{{ __('Merchandiser') }}</h2>
                <form method="POST" action="{{ route('supplier-orders.merchandiser', $order) }}" class="d-flex gap-2">
                    @csrf @method('PUT')
                    <select name="merchandiser_id" class="form-select form-select-sm @error('merchandiser_id') is-invalid @enderror">
                        <option value="">—</option>
                        @foreach($merchandisers as $m)
                            <option value="{{ $m->id }}" @selected((int) old('merchandiser_id', $order->merchandiser_id) === $m->id)>{{ $m->name }}</option>
                        @endforeach
                    </select>
                    <button class="btn btn-sm btn-light">{{ __('Save') }}</button>
                </form>
            </div>
        @endif
    </div>
</div>

{{-- The promised delivery date and its history (never overwritten without a reason). --}}
<div class="app-card p-3 mt-3">
    <h2 class="h6 mb-3"><i class="bi bi-calendar-event me-1"></i>{{ __('Delivery date history') }}</h2>
    @if($editable)
        <form method="POST" action="{{ route('supplier-orders.delivery-date', $order) }}" class="row g-2 align-items-end mb-3">
            @csrf
            <div class="col-md-3">
                <label class="form-label" for="new_delivery_date">{{ __('New delivery date') }}</label>
                <input id="new_delivery_date" type="date" name="delivery_date" required class="form-control @error('delivery_date') is-invalid @enderror" value="{{ old('delivery_date') }}">
            </div>
            <div class="col-md-7">
                <label class="form-label" for="date_reason">{{ __('Reason') }}</label>
                <input id="date_reason" name="reason" required maxlength="2000" class="form-control @error('reason') is-invalid @enderror" value="{{ old('reason') }}" placeholder="{{ __('Why the supplier moved the date') }}">
            </div>
            <div class="col-md-2"><button class="btn btn-primary w-100">{{ __('Change date') }}</button></div>
        </form>
    @endif
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('Changed on') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th>{{ __('Reason') }}</th><th>{{ __('By') }}</th></tr></thead>
            <tbody>
            @forelse($order->dateChanges->reverse() as $change)
                <tr>
                    <td class="app-num">{{ $change->created_at?->format('Y-m-d H:i') }}</td>
                    <td class="app-num">{{ $change->old_date?->format('Y-m-d') ?? '—' }}</td>
                    <td class="app-num">{{ $change->new_date?->format('Y-m-d') ?? '—' }}</td>
                    <td style="white-space:normal">{{ $change->reason }}</td>
                    <td>{{ $change->user?->name }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-muted small">{{ __('The delivery date has not moved.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Lines: each is a PI line at the supplier's price. --}}
<div class="app-card mt-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-3 pb-0">
        <h2 class="h6 mb-0">{{ __('Lines') }}</h2>
        @if($editable && $seeSuppliers && $orderablePis->isNotEmpty())
            <form method="GET" action="{{ route('supplier-orders.lines.create', $order) }}" class="d-flex gap-2">
                <select name="pi" class="form-select form-select-sm" required>
                    <option value="">{{ __('Add lines from a PI…') }}</option>
                    @foreach($orderablePis as $p)
                        <option value="{{ $p->id }}">{{ $p->reference() }} — {{ $p->client?->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-sm btn-light">{{ __('Add lines') }}</button>
            </form>
        @endif
    </div>
    <div class="table-responsive app-board mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('PI') }}</th><th>{{ __('Client') }}</th><th></th><th>{{ __('Item') }}</th>
                <th class="text-end">{{ __('Cartons') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                @if($seeSuppliers)<th class="text-end">{{ __('Unit price') }}</th><th class="text-end">{{ __('Amount') }}</th>@endif
                <th class="text-end">{{ __('Received cartons') }}</th><th class="text-end">{{ __('Loaded cartons') }}</th>
            </tr></thead>
            <tbody>
            @forelse($lines as $line)
                @php $pi = $line->piLine?->proformaInvoice; @endphp
                <tr>
                    <td>
                        @if($pi && $canSeePis)
                            <a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr">{{ $pi->reference() }}</a>
                        @else
                            <span class="app-ltr">{{ $pi?->reference() }}</span>
                        @endif
                        <span class="text-muted small">#{{ $line->piLine?->line_no }}</span>
                    </td>
                    <td>{{ $pi?->client?->name }}</td>
                    <td>@include('supplier-orders._photo', ['product' => $line->piLine?->product])</td>
                    <td style="white-space:normal; min-inline-size:14rem">
                        <div class="fw-semibold">{{ $line->piLine?->product?->name }}</div>
                        <div class="text-muted small">{{ $line->piLine?->description }}</div>
                    </td>
                    <td class="app-num text-end">{{ Quantity::trim($line->cartons) }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->total_qty) }} {{ $line->piLine?->unit }}</td>
                    @if($seeSuppliers)
                        <td class="app-num text-end">{{ Quantity::trim($line->unit_price) }}</td>
                        <td class="app-num text-end">{{ Quantity::money($line->amount) }}</td>
                    @endif
                    @php $received = (string) ($line->received_cartons ?? '0'); @endphp
                    <td class="app-num text-end {{ bccomp($received, (string) $line->cartons, 4) >= 0 ? 'text-success' : '' }}">{{ Quantity::trim($received) }}</td>
                    <td class="app-num text-end">{{ Quantity::trim($line->loaded_cartons ?? '0') }}</td>
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
</div>

{{-- The steps (S2's columns): state, due date and the actions. --}}
<div class="app-card mt-3" id="steps">
    <h2 class="h6 p-3 pb-0 mb-0">{{ __('Steps') }}</h2>
    <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead><tr>
                <th>{{ __('Step') }}</th><th>{{ __('State') }}</th><th>{{ __('Due date') }}</th><th>{{ __('Last recorded') }}</th>
                @if($canEdit && $order->status !== 'cancelled')<th style="min-inline-size:16rem"></th>@endif
            </tr></thead>
            <tbody>
            @foreach($steps as $step)
                @php $state = $states[$step->id]; $latest = $state->latest; @endphp
                <tr>
                    <td>
                        {{ $step->displayName() }}
                        @if($step->requires_attachment)<i class="bi bi-paperclip text-muted ms-1" title="{{ __('Needs a document') }}"></i>@endif
                        @if($step->kind !== 'manual')<div class="text-muted small">{{ ['payment' => __('Payment step'), 'receipt' => __('Completed by the goods receipt'), 'booking' => __('Completed by the booking'), 'loading' => __('Completed by the loading')][$step->kind] ?? '' }}</div>@endif
                    </td>
                    <td>@include('supplier-orders._pill', ['state' => $state]) <span class="small text-muted">{{ $state->label() }}</span></td>
                    <td class="app-num">{{ $state->dueOn?->format('Y-m-d') ?? '—' }}</td>
                    <td class="small" style="white-space:normal">
                        @if($latest)
                            <span class="app-num">{{ $latest->happened_on->format('Y-m-d') }}</span> · {{ $latest->user?->name }}
                            @if($latest->note)<div class="text-muted">{{ $latest->note }}</div>@endif
                        @else
                            —
                        @endif
                    </td>
                    @if($canEdit && $order->status !== 'cancelled')
                        <td class="small">
                            <div class="d-flex flex-wrap gap-1 align-items-start">
                            @if(! $latest || $latest->status !== 'done')
                                <details @if((int) old('step_id') === $step->id && old('action') === 'done') open @endif>
                                    <summary class="btn btn-sm btn-primary">{{ __('Mark done') }}</summary>
                                    <form method="POST" action="{{ route('supplier-orders.steps.done', [$order, $step]) }}" enctype="multipart/form-data" class="mt-2" style="min-inline-size:16rem">
                                        @csrf
                                        <input type="hidden" name="step_id" value="{{ $step->id }}"><input type="hidden" name="action" value="done">
                                        <label class="form-label small mb-1">{{ __('Date') }}</label>
                                        <input type="date" name="happened_on" required max="{{ now()->toDateString() }}" class="form-control form-control-sm mb-2" value="{{ now()->toDateString() }}">
                                        <label class="form-label small mb-1">{{ __('Note') }}</label>
                                        <input name="note" maxlength="2000" class="form-control form-control-sm mb-2">
                                        @if($step->kind === 'payment' && $payments->isNotEmpty())
                                            <label class="form-label small mb-1">{{ __('Payment') }}</label>
                                            <select name="payment_id" class="form-select form-select-sm mb-2">
                                                <option value="">—</option>
                                                @foreach($payments as $payment)
                                                    <option value="{{ $payment->id }}">{{ $payment->number }} · {{ $payment->paid_on?->format('Y-m-d') }} · {{ Quantity::money($payment->amount) }} {{ $payment->currency }}</option>
                                                @endforeach
                                            </select>
                                        @endif
                                        <label class="form-label small mb-1">{{ __('Files') }} @if($step->requires_attachment)<span class="text-danger">*</span>@endif</label>
                                        <input type="file" name="files[]" multiple class="form-control form-control-sm mb-2" @if($step->requires_attachment) required @endif>
                                        <button class="btn btn-sm btn-primary">{{ __('Save') }}</button>
                                    </form>
                                </details>
                            @endif
                            @if(! $latest || $latest->status !== 'skipped')
                                <details>
                                    <summary class="btn btn-sm btn-light">{{ __('Not applicable') }}</summary>
                                    <form method="POST" action="{{ route('supplier-orders.steps.skip', [$order, $step]) }}" class="mt-2" style="min-inline-size:14rem">
                                        @csrf
                                        <input name="reason" required maxlength="2000" class="form-control form-control-sm mb-2" placeholder="{{ __('Reason') }}">
                                        <button class="btn btn-sm btn-light">{{ __('Save') }}</button>
                                    </form>
                                </details>
                            @endif
                            @if($latest && $latest->status !== 'reopened')
                                <details>
                                    <summary class="btn btn-sm btn-light"><i class="bi bi-arrow-counterclockwise me-1"></i>{{ __('Reopen') }}</summary>
                                    <form method="POST" action="{{ route('supplier-orders.steps.reopen', [$order, $step]) }}" class="mt-2" style="min-inline-size:14rem">
                                        @csrf
                                        <input name="reason" required maxlength="2000" class="form-control form-control-sm mb-2" placeholder="{{ __('Reason') }}">
                                        <button class="btn btn-sm btn-light">{{ __('Save') }}</button>
                                    </form>
                                </details>
                            @endif
                            </div>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Every step event, newest first: the log is append-only, so this is the full story. --}}
<div class="app-card mt-3">
    <h2 class="h6 p-3 pb-0 mb-0">{{ __('Step history') }}</h2>
    <div class="table-responsive mt-2">
        <table class="table table-sm align-middle mb-0">
            <thead><tr><th>{{ __('Recorded') }}</th><th>{{ __('Step') }}</th><th>{{ __('Event') }}</th><th>{{ __('Date') }}</th><th>{{ __('Note') }}</th><th>{{ __('Payment') }}</th><th>{{ __('By') }}</th></tr></thead>
            <tbody>
            @forelse($events as $event)
                <tr>
                    <td class="app-num small">{{ $event->created_at?->format('Y-m-d H:i') }}</td>
                    <td>{{ $event->step?->displayName() }}</td>
                    <td>{{ ['done' => __('Done'), 'skipped' => __('Not applicable'), 'reopened' => __('Reopened')][$event->status] ?? $event->status }}</td>
                    <td class="app-num">{{ $event->happened_on?->format('Y-m-d') }}</td>
                    <td class="small" style="white-space:normal">{{ $event->note }}</td>
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
                <tr><td colspan="7" class="text-muted small">{{ __('No step recorded yet.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- The documents (stamped contract, sample photos, artwork) carry supplier details and prices. --}}
@if($seeSuppliers)
    @include('partials.attachments', ['attachable' => $order, 'type' => 'supplier-orders'])
@endif
@endsection
