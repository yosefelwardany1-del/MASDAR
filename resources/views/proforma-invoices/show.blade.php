@extends('layouts.app')

@section('title', $pi->reference())

@php
    use App\Services\ProformaInvoices\PiAmounts;
    use Illuminate\Support\Facades\Route;

    $money = fn (?string $amount) => PiAmounts::money($amount, $pi->currency);
    $photos = Route::has('products.photo');
    $columns = 11 + ($seeSuppliers ? 2 : 0) + ($lineForms ? 1 : 0);
    $revisable = $pi->is_current && in_array($pi->status, \App\Services\ProformaInvoices\ProformaInvoiceService::REVISABLE, true) && ! $pendingRevision;
@endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <h1 class="h4 mb-0"><span class="app-ltr">{{ $pi->reference() }}</span></h1>
        @include('proforma-invoices._status', ['pi' => $pi])
        @unless($pi->is_current)<span class="app-pill app-pill--skip">{{ __('Not the current revision') }}</span>@endunless
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="{{ route('proforma-invoices.print', $pi) }}" class="btn btn-light" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i>{{ __('Print') }}</a>
        @if($canEdit)
            @if($pi->isDraft())
                <a href="{{ route('proforma-invoices.edit', $pi) }}" class="btn btn-light"><i class="bi bi-pencil me-1"></i>{{ __('Edit header') }}</a>
                @if($mayIssue)
                    <form method="POST" action="{{ route('proforma-invoices.issue', $pi) }}" onsubmit="return confirm(@js(__('Issue this PI? Once issued it never changes; a change needs a new revision.')));">
                        @csrf <button class="btn btn-primary"><i class="bi bi-send me-1"></i>{{ __('Issue') }}</button>
                    </form>
                @endif
            @endif
            @if($pi->status === 'issued')
                <form method="POST" action="{{ route('proforma-invoices.accept', $pi) }}">
                    @csrf <button class="btn btn-light"><i class="bi bi-hand-thumbs-up me-1"></i>{{ __('Accepted by the client') }}</button>
                </form>
            @endif
            @if(in_array($pi->status, ['issued', 'accepted'], true))
                <form method="POST" action="{{ route('proforma-invoices.confirm', $pi) }}" onsubmit="return confirm(@js(__('Confirm that the client\'s deposit has arrived?')));">
                    @csrf <button class="btn btn-light"><i class="bi bi-check2-circle me-1"></i>{{ __('Deposit received: confirm') }}</button>
                </form>
            @endif
            @if($revisable)
                <form method="POST" action="{{ route('proforma-invoices.revise', $pi) }}" onsubmit="return confirm(@js(__('Make a new revision? It starts as a draft copy; this one stays current until the new one is issued.')));">
                    @csrf <button class="btn btn-light"><i class="bi bi-files me-1"></i>{{ __('New revision') }}</button>
                </form>
            @endif
            @if(in_array($pi->status, ['draft', 'issued', 'accepted', 'confirmed'], true))
                <button type="button" class="btn btn-light text-danger" data-bs-toggle="collapse" data-bs-target="#pi-cancel" aria-expanded="false"><i class="bi bi-x-circle me-1"></i>{{ __('Cancel PI') }}</button>
            @endif
        @endif
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

@if($canEdit && $pi->isDraft() && ! $mayIssue)
    <div class="alert alert-info py-2">{{ __('Issuing a PI needs a manager\'s approval: a manager issues it once it is ready.') }}</div>
@endif
@if($pendingRevision && $pendingRevision->id !== $pi->id)
    <div class="alert alert-warning py-2">
        {{ __('A draft revision of this PI is being prepared:') }}
        <a href="{{ route('proforma-invoices.show', $pendingRevision) }}" class="app-ltr">{{ $pendingRevision->reference() }}</a>
    </div>
@endif
@if(! $pi->is_current && $current)
    <div class="alert alert-secondary py-2">
        {{ __('This is not the current revision.') }}
        <a href="{{ route('proforma-invoices.show', $current) }}">{{ __('Open the current one: :reference', ['reference' => $current->reference()]) }}</a>
    </div>
@endif

@if($canEdit && in_array($pi->status, ['draft', 'issued', 'accepted', 'confirmed'], true))
    <div class="collapse {{ $errors->has('cancel_reason') ? 'show' : '' }}" id="pi-cancel">
        <form method="POST" action="{{ route('proforma-invoices.cancel', $pi) }}" class="app-card p-3 mb-3">
            @csrf
            <label class="form-label" for="cancel_reason">{{ __('Why is this PI cancelled?') }} <span class="text-danger">*</span></label>
            <textarea id="cancel_reason" name="cancel_reason" rows="2" class="form-control @error('cancel_reason') is-invalid @enderror" required>{{ old('cancel_reason') }}</textarea>
            @error('cancel_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <button class="btn btn-danger mt-2">{{ __('Cancel this PI') }}</button>
        </form>
    </div>
@endif

<div class="row g-3">
    <div class="col-lg-8">
        <div class="app-card p-3 h-100">
            <dl class="row mb-0">
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Client') }}</dt>
                <dd class="col-sm-9">
                    @if(Route::has('clients.show'))<a href="{{ route('clients.show', $pi->client) }}">{{ $pi->client?->name }}</a>@else{{ $pi->client?->name }}@endif
                    @if($pi->client?->accountManager)<span class="text-muted small">· {{ __('Account manager') }}: {{ $pi->client->accountManager->name }}</span>@endif
                </dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Shipped to') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $pi->shipped_to ?: '—' }}</span></dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('PO number') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $pi->po_number ?: '—' }}</span></dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('PO date') }}</dt><dd class="col-sm-9 app-num">{{ $pi->po_date?->toDateString() ?? '—' }}</dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Currency') }}</dt><dd class="col-sm-9">{{ $pi->currency }}</dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Deposit %') }}</dt><dd class="col-sm-9 app-num">{{ PiAmounts::number((string) $pi->deposit_percent, 0, 2) }}%</dd>
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Payment terms') }}</dt><dd class="col-sm-9"><span class="app-ltr">{{ $pi->terms_text }}</span></dd>
                @if($pi->notes)
                    <dt class="col-sm-3 text-muted fw-normal">{{ __('Internal notes') }}</dt><dd class="col-sm-9" style="white-space:pre-line">{{ $pi->notes }}</dd>
                @endif
                <dt class="col-sm-3 text-muted fw-normal">{{ __('Created') }}</dt><dd class="col-sm-9">{{ $pi->created_at?->toDateString() }} — {{ $pi->creator?->name }}</dd>
                @if($pi->issued_at)
                    <dt class="col-sm-3 text-muted fw-normal">{{ __('Issued') }}</dt><dd class="col-sm-9">{{ $pi->issued_at->toDateString() }} — {{ $pi->issuer?->name }}</dd>
                @endif
                @if($pi->accepted_at)
                    <dt class="col-sm-3 text-muted fw-normal">{{ __('Accepted') }}</dt><dd class="col-sm-9">{{ $pi->accepted_at->toDateString() }}</dd>
                @endif
                @if($pi->confirmed_at)
                    <dt class="col-sm-3 text-muted fw-normal">{{ __('Confirmed') }}</dt><dd class="col-sm-9">{{ $pi->confirmed_at->toDateString() }}</dd>
                @endif
                @if($pi->cancelled_at)
                    <dt class="col-sm-3 text-muted fw-normal">{{ __('Cancelled') }}</dt><dd class="col-sm-9">{{ $pi->cancelled_at->toDateString() }} — {{ $pi->cancel_reason }}</dd>
                @endif
            </dl>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="app-card h-100">
            <div class="app-stat">
                <div class="app-stat__label">{{ __('Total') }}</div>
                <div class="app-stat__value app-num">{{ $money($totals->amount) }}</div>
            </div>
            <div class="app-stat border-top">
                <div class="app-stat__label">{{ __('Deposit required') }} ({{ PiAmounts::number((string) $pi->deposit_percent, 0, 2) }}%)</div>
                <div class="fw-semibold app-num">{{ $money($depositRequired) }}</div>
            </div>
            <div class="app-stat border-top">
                <div class="app-stat__label">{{ __('Paid') }}</div>
                <div class="fw-semibold app-num">{{ $money($paid) }}</div>
            </div>
            <div class="app-stat border-top">
                <div class="app-stat__label">{{ __('Balance due') }}</div>
                <div class="fw-semibold app-num">{{ $money($outstanding) }}</div>
            </div>
        </div>
    </div>
</div>

<div class="app-card mt-3" id="lines">
    <div class="p-3 pb-0 fw-semibold">{{ __('Lines') }}</div>
    <div class="table-responsive app-board">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('No.') }}</th><th>{{ __('Picture') }}</th><th>{{ __('Item name & description') }}</th>
                <th class="text-end">{{ __('CTN') }}</th><th class="text-end">{{ __('Qty per carton') }}</th><th class="text-end">{{ __('Total qty') }}</th>
                <th>{{ __('Unit') }}</th><th class="text-end">{{ __('Price') }}</th><th class="text-end">{{ __('Amount') }}</th>
                <th class="text-end">{{ __('Total CBM') }}</th><th class="text-end">{{ __('Total KG') }}</th>
                @if($seeSuppliers)<th>{{ __('Supplier') }}</th><th class="text-end">{{ __('Supplier price') }}</th>@endif
                @if($lineForms)<th></th>@endif
            </tr></thead>
            <tbody>
            @forelse($pi->lines as $line)
                <tr>
                    <td class="app-num">{{ $line->line_no }}</td>
                    <td>
                        @if($photos && $line->product?->photo)
                            <img class="app-thumb" src="{{ route('products.photo', $line->product_id) }}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                        @endif
                    </td>
                    <td style="white-space:pre-line;min-inline-size:14rem" dir="ltr">{{ $line->itemText() }}<div class="text-muted small">{{ $line->product?->code }}</div></td>
                    <td class="app-num text-end">{{ PiAmounts::number($line->cartons) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($line->qty_per_carton) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($line->total_qty) }}</td>
                    <td>{{ $units[$line->unit] ?? $line->unit }}</td>
                    <td class="app-num text-end">{{ $money($line->unit_price) }}</td>
                    <td class="app-num text-end">{{ $money($line->amount) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($line->total_cbm) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($line->total_kg) }}</td>
                    @if($seeSuppliers)
                        <td>{{ $line->supplier?->name ?? '—' }}</td>
                        <td class="app-num text-end">{{ $line->supplier_price !== null ? PiAmounts::money($line->supplier_price, $line->supplier_currency ?? '') : '—' }}</td>
                    @endif
                    @if($lineForms)
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#line-edit-{{ $line->id }}" title="{{ __('Edit') }}"><i class="bi bi-pencil"></i></button>
                            <form method="POST" action="{{ route('proforma-invoices.lines.destroy', [$pi, $line]) }}" class="d-inline" onsubmit="return confirm(@js(__('Remove this line?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger" title="{{ __('Remove') }}"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    @endif
                </tr>
                @if($lineForms)
                    <tr class="collapse {{ old('form') === 'line-'.$line->id ? 'show' : '' }}" id="line-edit-{{ $line->id }}">
                        <td colspan="{{ $columns }}" class="bg-body-tertiary">
                            <form method="POST" action="{{ route('proforma-invoices.lines.update', [$pi, $line]) }}" class="p-2">
                                @csrf @method('PUT')
                                @include('proforma-invoices._line-fields', ['line' => $line, 'formKey' => 'line-'.$line->id])
                                <div class="mt-2 d-flex gap-2">
                                    <button class="btn btn-sm btn-primary">{{ __('Save line') }}</button>
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#line-edit-{{ $line->id }}">{{ __('Close') }}</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr><td colspan="{{ $columns }}" class="app-empty">{{ __('No lines yet.') }}</td></tr>
            @endforelse
            </tbody>
            @if($pi->lines->isNotEmpty())
                <tfoot><tr class="fw-semibold">
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($totals->total_qty) }}</td>
                    <td></td><td></td>
                    <td class="app-num text-end">{{ $money($totals->amount) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($totals->total_cbm) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::number($totals->total_kg) }}</td>
                    @if($seeSuppliers)<td colspan="2"></td>@endif
                    @if($lineForms)<td></td>@endif
                </tr></tfoot>
            @endif
        </table>
    </div>
    <div class="p-3 border-top text-center fw-semibold"><span class="app-ltr">{{ $pi->terms_text }}</span></div>
</div>

@if($lineForms)
    <div class="app-card p-3 mt-3">
        <div class="fw-semibold mb-2">{{ __('Add a line') }}</div>
        <form method="POST" action="{{ route('proforma-invoices.lines.store', $pi) }}" enctype="multipart/form-data">
            @csrf
            @include('proforma-invoices._line-fields', ['line' => null, 'formKey' => 'add'])
            <button class="btn btn-primary mt-3"><i class="bi bi-plus-lg me-1"></i>{{ __('Add line') }}</button>
        </form>
    </div>
@endif

<div class="row g-3 mt-0">
    <div class="col-lg-5">
        <div class="app-card p-3 h-100">
            <div class="fw-semibold mb-2">{{ __('Revisions') }}</div>
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>{{ __('Revision') }}</th><th>{{ __('Status') }}</th><th>{{ __('Issued') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead>
                <tbody>
                @foreach($revisions as $rev)
                    <tr @class(['table-active' => $rev->id === $pi->id])>
                        <td><a href="{{ route('proforma-invoices.show', $rev) }}" class="app-ltr">{{ $rev->reference() }}</a>
                            @if($rev->is_current)<span class="text-muted small">· {{ __('current') }}</span>@endif</td>
                        <td>@include('proforma-invoices._status', ['pi' => $rev])</td>
                        <td class="app-num">{{ $rev->issued_at?->toDateString() ?? '—' }}</td>
                        <td class="app-num text-end">{{ PiAmounts::money($rev->total_amount, $rev->currency) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="app-card p-3 h-100">
            <div class="fw-semibold mb-2">{{ __('Payments') }}</div>
            <div class="d-flex flex-wrap gap-4 mb-2">
                <div><span class="text-muted small">{{ __('Paid') }}</span><div class="fw-semibold app-num">{{ $money($paid) }}</div></div>
                <div><span class="text-muted small">{{ __('Deposit required') }}</span><div class="fw-semibold app-num">{{ $money($depositRequired) }}</div></div>
                <div><span class="text-muted small">{{ __('Balance due') }}</span><div class="fw-semibold app-num">{{ $money($outstanding) }}</div></div>
            </div>
            <div class="form-text mb-2">{{ __('Client receipts in :currency linked to any revision of this PI.', ['currency' => $pi->currency]) }}</div>
            @if($payments !== null)
                <table class="table table-sm align-middle mb-0">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Number') }}</th><th>{{ __('Revision') }}</th><th>{{ __('Account') }}</th><th class="text-end">{{ __('Amount') }}</th></tr></thead>
                    <tbody>
                    @forelse($payments as $payment)
                        @php $counted = $payment->kind === 'client_receipt' && $payment->currency === $pi->currency; @endphp
                        <tr @class(['text-muted' => ! $counted])>
                            <td class="app-num">{{ $payment->paid_on?->toDateString() }}</td>
                            <td>@if(Route::has('payments.show'))<a href="{{ route('payments.show', $payment) }}" class="app-ltr">{{ $payment->number }}</a>@else<span class="app-ltr">{{ $payment->number }}</span>@endif
                                @if($payment->reverses_payment_id)<span class="small">· {{ __('reversal') }}</span>@endif</td>
                            <td class="app-ltr">{{ $payment->proformaInvoice?->reference() }}</td>
                            <td>{{ $payment->moneyAccount?->name }}</td>
                            <td class="app-num text-end">{{ PiAmounts::money($payment->signed_amount, $payment->currency) }}
                                @unless($counted)<div class="small">{{ __('not counted') }}</div>@endunless</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="app-empty">{{ __('No payments yet.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</div>

@include('partials.attachments', ['attachable' => $pi, 'type' => 'proforma-invoices'])
@endsection

@if($lineForms)
    @push('scripts')
    <script>
        document.querySelectorAll('[data-app-product-select]').forEach(function (select) {
            var form = select.closest('form');
            var sync = function () {
                form.querySelectorAll('[data-app-new-product]').forEach(function (el) { el.hidden = select.value !== 'new'; });
            };
            select.addEventListener('change', sync);
            sync();
        });
    </script>
    @endpush
@endif
