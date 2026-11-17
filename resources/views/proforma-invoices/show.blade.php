@extends('layouts.app')

@section('title', $pi->reference())

@php
    use App\Models\BusinessSetting;
    use App\Models\Setting;
    use App\Services\ProformaInvoices\PiAmounts;
    use App\Services\ProformaInvoices\ProformaInvoiceService;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\HtmlString;

    $user = auth()->user();
    $money = fn (?string $amount) => PiAmounts::money($amount, $pi->currency);
    $photos = Route::has('products.photo');
    $productLinks = Route::has('products.show') && $user->can('products.view');
    $columns = 11 + ($seeSuppliers ? 1 : 0) + ($lineForms ? 1 : 0);
    $revisable = $pi->is_current && in_array($pi->status, ProformaInvoiceService::REVISABLE, true) && ! $pendingRevision;
    $live = in_array($pi->status, ['draft', 'issued', 'accepted', 'confirmed'], true);

    // The money panel: what the deposit asks for and whether it has come in.
    $deposit = PiAmounts::number((string) $pi->deposit_percent, 0, 2);
    $hasTotal = bccomp((string) $totals->amount, '0', 4) > 0;
    $hasDeposit = bccomp((string) $depositRequired, '0', 4) > 0;
    $depositIn = $hasDeposit && bccomp((string) $paid, (string) $depositRequired, 4) >= 0;
    $settled = $hasTotal && bccomp((string) $outstanding, '0', 4) <= 0;
    $canRecordPayment = $pi->is_current && in_array($pi->status, ['issued', 'accepted', 'confirmed'], true)
        && Route::has('payments.create') && $user->can('payments.edit');

    // The status path, draft → issued → accepted → confirmed, with who and when.
    $path = ['draft' => __('Draft'), 'issued' => __('Issued'), 'accepted' => __('Accepted'), 'confirmed' => __('Confirmed')];
    $when = ['draft' => $pi->created_at, 'issued' => $pi->issued_at, 'accepted' => $pi->accepted_at, 'confirmed' => $pi->confirmed_at];
    $who = ['draft' => $pi->creator?->name, 'issued' => $pi->issuer?->name];
    $at = array_search($pi->status, array_keys($path), true);
    $steps = [];
    foreach (array_keys($path) as $i => $key) {
        $state = match (true) {
            $at === false => $when[$key] ? 'done' : 'skip',
            $i < $at => $when[$key] ? 'done' : 'skip',
            $i === $at => $key === 'confirmed' ? 'done' : 'current',
            default => 'todo',
        };
        $steps[] = ['label' => $path[$key], 'state' => $state, 'date' => $when[$key]?->toDateString(), 'hint' => $who[$key] ?? null];
    }
    $nextStep = [
        'draft' => __('Add the lines, then issue the PI to the client.'),
        'issued' => __('With the client: mark it accepted, or confirm once the deposit arrives.'),
        'accepted' => __('Waiting for the deposit: confirm once it arrives.'),
        'confirmed' => __('The client\'s deposit has arrived.'),
        'superseded' => __('A newer revision replaced this one.'),
    ][$pi->status] ?? null;

    // The letterhead, as on MASDAR's printed PI.
    $setting = Setting::current();
    $address = BusinessSetting::current()->company_address;
    $logo = $setting->logo ? route('settings.logo') : asset('images/masdar-mark.png');
    $clientLink = $pi->client && Route::has('clients.show') && $user->can('clients.view') ? route('clients.show', $pi->client) : null;
    $heading = new HtmlString('<span class="app-ltr">'.e($pi->reference()).'</span>');
    $subtitle = $pi->client ? new HtmlString(
        ($clientLink ? '<a href="'.e($clientLink).'">'.e($pi->client->name).'</a>' : e($pi->client->name))
        .($pi->client->accountManager ? ' · '.e(__('Account manager')).': '.e($pi->client->accountManager->name) : '')
    ) : null;
@endphp

@section('content')
<x-page-header :title="$heading" :subtitle="$subtitle"
               :back="route('proforma-invoices.index')" :back-label="__('Proforma invoices')">
    <x-slot:meta>
        @include('proforma-invoices._status', ['pi' => $pi])
        @unless($pi->is_current)<span class="app-pill app-pill--skip"><i class="bi bi-clock-history" aria-hidden="true"></i>{{ __('Not the current revision') }}</span>@endunless
    </x-slot:meta>
    <x-slot:actions>
        <a href="{{ route('proforma-invoices.print', $pi) }}" class="btn btn-light" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i>{{ __('Print') }}</a>
        @if($canEdit)
            @if($pi->isDraft())
                <a href="{{ route('proforma-invoices.edit', $pi) }}" class="btn btn-light"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit header') }}</a>
                @if($mayIssue)
                    <form method="POST" action="{{ route('proforma-invoices.issue', $pi) }}" onsubmit="return confirm(@js(__('Issue this PI? Once issued it never changes; a change needs a new revision.')));">
                        @csrf <button class="btn btn-primary"><i class="bi bi-send" aria-hidden="true"></i>{{ __('Issue') }}</button>
                    </form>
                @endif
            @endif
            @if($pi->status === 'issued')
                <form method="POST" action="{{ route('proforma-invoices.accept', $pi) }}">
                    @csrf <button class="btn btn-light"><i class="bi bi-hand-thumbs-up" aria-hidden="true"></i>{{ __('Accepted by the client') }}</button>
                </form>
            @endif
            @if(in_array($pi->status, ['issued', 'accepted'], true))
                <form method="POST" action="{{ route('proforma-invoices.confirm', $pi) }}" onsubmit="return confirm(@js(__('Confirm that the client\'s deposit has arrived?')));">
                    @csrf <button class="btn {{ $pi->status === 'accepted' ? 'btn-primary' : 'btn-light' }}"><i class="bi bi-check2-circle" aria-hidden="true"></i>{{ __('Deposit received: confirm') }}</button>
                </form>
            @endif
            @if($revisable)
                <form method="POST" action="{{ route('proforma-invoices.revise', $pi) }}" onsubmit="return confirm(@js(__('Make a new revision? It starts as a draft copy; this one stays current until the new one is issued.')));">
                    @csrf <button class="btn btn-light"><i class="bi bi-files" aria-hidden="true"></i>{{ __('New revision') }}</button>
                </form>
            @endif
            @if($live)
                <span class="app-doc-actions__sep" aria-hidden="true"></span>
                <button type="button" class="btn btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#pi-cancel" aria-expanded="{{ $errors->has('cancel_reason') ? 'true' : 'false' }}" aria-controls="pi-cancel"><i class="bi bi-x-circle" aria-hidden="true"></i>{{ __('Cancel PI') }}</button>
            @endif
        @endif
    </x-slot:actions>
</x-page-header>

@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0 ps-3">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    </div>
@endif

@if($canEdit && $pi->isDraft() && ! $mayIssue)
    <div class="alert alert-info py-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ __('Issuing a PI needs a manager\'s approval: a manager issues it once it is ready.') }}</div>
@endif
@if($pendingRevision && $pendingRevision->id !== $pi->id)
    <div class="alert alert-warning py-2">
        <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>{{ __('A draft revision of this PI is being prepared:') }}
        <a href="{{ route('proforma-invoices.show', $pendingRevision) }}" class="app-ltr">{{ $pendingRevision->reference() }}</a>
    </div>
@endif
@if(! $pi->is_current && $current)
    <div class="alert alert-info py-2">
        <i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ __('This is not the current revision.') }}
        <a href="{{ route('proforma-invoices.show', $current) }}">{{ __('Open the current one: :reference', ['reference' => $current->reference()]) }}</a>
    </div>
@endif

@if($canEdit && $live)
    <div class="collapse {{ $errors->has('cancel_reason') ? 'show' : '' }}" id="pi-cancel">
        <form method="POST" action="{{ route('proforma-invoices.cancel', $pi) }}" class="app-card app-doc-cancel mb-3">
            @csrf
            <label class="form-label" for="cancel_reason">{{ __('Why is this PI cancelled?') }} <span class="text-danger">*</span></label>
            <textarea id="cancel_reason" name="cancel_reason" rows="2" class="form-control @error('cancel_reason') is-invalid @enderror" required>{{ old('cancel_reason') }}</textarea>
            @error('cancel_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="d-flex flex-wrap gap-2 mt-2">
                <button class="btn btn-danger"><i class="bi bi-x-circle" aria-hidden="true"></i>{{ __('Cancel this PI') }}</button>
                <button type="button" class="btn btn-light" data-bs-toggle="collapse" data-bs-target="#pi-cancel">{{ __('Close') }}</button>
            </div>
        </form>
    </div>
@endif

<div class="app-doc-top">
    <div class="app-doc-top__main">
    {{-- Where the PI stands: the status path, who moved it, and what comes next. --}}
    <section class="app-card app-doc-status" aria-labelledby="pi-status-head">
        <div class="app-card__head border-bottom" id="pi-status-head"><i class="bi bi-signpost-split" aria-hidden="true"></i>{{ __('Status') }}</div>
        <div class="app-section__body">
            <x-stepper :steps="$steps" :label="__('Status')" />
            @if($pi->cancelled_at)
                <div class="app-doc-note app-doc-note--late">
                    <i class="bi bi-x-circle" aria-hidden="true"></i>
                    <div><strong>{{ __('Cancelled') }}</strong> · <span class="app-num">{{ $pi->cancelled_at->toDateString() }}</span>
                        @if($pi->cancel_reason)<div class="app-doc-note__text">{{ $pi->cancel_reason }}</div>@endif</div>
                </div>
            @elseif($nextStep)
                <div class="app-doc-note"><i class="bi bi-arrow-return-right" aria-hidden="true"></i><div>{{ $nextStep }}</div></div>
            @endif
            @if($pi->notes)
                <div class="app-doc-internal mt-3">
                    <div class="app-doc-internal__label"><i class="bi bi-lock" aria-hidden="true"></i>{{ __('Internal notes') }} · {{ __('Not printed on the PI.') }}</div>
                    <div class="app-doc-internal__text">{{ $pi->notes }}</div>
                </div>
            @endif
        </div>
    </section>

    {{-- Every revision of this PI number, the one on screen marked. --}}
    <x-section :title="__('Revisions')" icon="files" flush>
            <ol class="app-list app-doc-revisions">
                @foreach($revisions as $rev)
                    <li class="app-list__row {{ $rev->id === $pi->id ? 'is-here' : '' }}">
                        <span class="app-doc-revisions__no app-num" aria-hidden="true">{{ $rev->revision }}</span>
                        <div class="app-list__main">
                            <a href="{{ route('proforma-invoices.show', $rev) }}" class="app-list__title app-ltr" @if($rev->id === $pi->id) aria-current="page" @endif>{{ $rev->reference() }}</a>
                            <div class="app-list__sub">
                                @if($rev->is_current)<span class="fw-semibold">{{ __('current') }}</span> · @endif
                                {{ __('Issued') }}: <span class="app-num">{{ $rev->issued_at?->toDateString() ?? '—' }}</span>
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="app-num fw-semibold">{{ PiAmounts::money($rev->total_amount, $rev->currency) }}</div>
                            @include('proforma-invoices._status', ['pi' => $rev])
                        </div>
                    </li>
                @endforeach
            </ol>
        </x-section>
    </div>

    {{-- The money: total, what has been paid, the deposit and what is still due. --}}
    <section class="app-card app-doc-summary" aria-labelledby="pi-summary-head">
        <div class="app-card__head border-bottom" id="pi-summary-head"><i class="bi bi-wallet2" aria-hidden="true"></i>{{ __('Payment summary') }}
            <span class="app-doc-summary__currency ms-auto">{{ $pi->currency }}</span></div>
        <div class="app-doc-summary__body">
            <div class="app-doc-summary__total">
                <span class="app-doc-summary__label">{{ __('Total') }}</span>
                <span class="app-doc-summary__amount app-num">{{ $money($totals->amount) }}</span>
            </div>
            <x-progress :value="(float) $paid" :max="(float) $totals->amount" :label="__('Paid against total')" :tone="$settled ? 'done' : 'gold'" />
            <dl class="app-doc-summary__rows">
                <div class="app-doc-summary__row">
                    <dt>{{ __('Paid') }}</dt>
                    <dd class="app-num">{{ $money($paid) }}</dd>
                </div>
                <div class="app-doc-summary__row">
                    <dt>{{ __('Deposit required') }} <span class="app-num">({{ $deposit }}%)</span></dt>
                    <dd><span class="app-num">{{ $money($depositRequired) }}</span>
                        @if($hasDeposit && $pi->status !== 'draft')
                            @if($depositIn)
                                <x-status tone="done" class="app-doc-summary__flag">{{ __('Deposit received') }}</x-status>
                            @elseif(in_array($pi->status, ['issued', 'accepted'], true))
                                <x-status tone="due" class="app-doc-summary__flag">{{ __('Deposit not received yet') }}</x-status>
                            @endif
                        @endif
                    </dd>
                </div>
                <div class="app-doc-summary__row app-doc-summary__row--due">
                    <dt>{{ __('Balance due') }}</dt>
                    <dd class="app-num">{{ $money($outstanding) }}</dd>
                </div>
            </dl>
            <div class="app-doc-summary__facts">
                <span><span class="app-doc-summary__fact-label">{{ __('Lines') }}</span> <span class="app-num">{{ $pi->lines->count() }}</span></span>
                <span><span class="app-doc-summary__fact-label">{{ __('Total CBM') }}</span> <span class="app-num">{{ PiAmounts::number($totals->total_cbm) }}</span></span>
                <span><span class="app-doc-summary__fact-label">{{ __('Total KG') }}</span> <span class="app-num">{{ PiAmounts::number($totals->total_kg) }}</span></span>
            </div>
            @if($canRecordPayment)
                <a href="{{ route('payments.create', ['kind' => 'client_receipt', 'pi' => $pi->id]) }}" class="btn btn-light w-100 mt-3"><i class="bi bi-cash-coin" aria-hidden="true"></i>{{ __('Record a payment') }}</a>
            @endif
        </div>
    </section>
</div>

{{-- The document itself, laid out like MASDAR's printed PI: letterhead, parties, lines, totals, terms. --}}
<article class="app-card app-doc-sheet mt-3" id="lines" aria-labelledby="pi-sheet-title">
    <header class="app-doc-sheet__head">
        <div class="app-doc-sheet__brand">
            <span class="app-doc-sheet__logo"><img src="{{ $logo }}" alt="" width="40" height="40"></span>
            <div class="min-w-0">
                <div class="app-doc-sheet__company"><bdi>{{ $setting->company_name }}</bdi></div>
                @if($address)<div class="app-doc-sheet__address"><bdi>{{ $address }}</bdi></div>@endif
            </div>
        </div>
        <div class="app-doc-sheet__title">
            <h2 id="pi-sheet-title">{{ __('Proforma invoice') }}</h2>
            <div class="app-doc-sheet__ref"><span class="app-ltr">{{ $pi->reference() }}</span>
                @if($pi->issued_at) · <span class="app-num">{{ $pi->issued_at->toDateString() }}</span>@endif</div>
        </div>
    </header>

    <div class="app-doc-sheet__parties">
        <dl class="app-doc-fields">
            <div><dt>{{ __('To') }}</dt><dd>@if($clientLink)<a href="{{ $clientLink }}">{{ $pi->client?->name }}</a>@else{{ $pi->client?->name ?? '—' }}@endif</dd></div>
            <div><dt>{{ __('Shipped to') }}</dt><dd><span class="app-ltr">{{ $pi->shipped_to ?: '—' }}</span></dd></div>
        </dl>
        <dl class="app-doc-fields">
            <div><dt>{{ __('PO number') }}</dt><dd><span class="app-ltr">{{ $pi->po_number ?: '—' }}</span></dd></div>
            <div><dt>{{ __('PO date') }}</dt><dd class="app-num">{{ $pi->po_date?->toDateString() ?? '—' }}</dd></div>
        </dl>
        <dl class="app-doc-fields">
            <div><dt>{{ __('Currency') }}</dt><dd>{{ $pi->currency }}</dd></div>
            <div><dt>{{ __('Deposit %') }}</dt><dd class="app-num">{{ $deposit }}%</dd></div>
        </dl>
    </div>

    <div class="table-responsive app-doc-lines">
        <table class="table align-middle mb-0">
            <caption class="visually-hidden">{{ __('Lines') }}</caption>
            <thead><tr>
                <th scope="col" class="app-doc-lines__no">{{ __('No.') }}</th>
                <th scope="col" class="app-doc-lines__pic">{{ __('Picture') }}</th>
                <th scope="col">{{ __('Item name & description') }}</th>
                <th scope="col" class="text-end">{{ __('CTN') }}</th>
                <th scope="col" class="text-end">{{ __('Qty per carton') }}</th>
                <th scope="col" class="text-end">{{ __('Total qty') }}</th>
                <th scope="col">{{ __('Unit') }}</th>
                <th scope="col" class="text-end">{{ __('Price') }}</th>
                <th scope="col" class="text-end">{{ __('Amount') }}</th>
                <th scope="col" class="text-end">{{ __('Total CBM') }}</th>
                <th scope="col" class="text-end">{{ __('Total KG') }}</th>
                @if($seeSuppliers)
                    <th scope="col" class="app-doc-lines__internal" title="{{ __('Never printed on the PI.') }}"><i class="bi bi-lock" aria-hidden="true"></i> {{ __('Supplier') }}<span class="app-doc-lines__subhead">{{ __('Supplier price') }}</span></th>
                @endif
                @if($lineForms)<th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>@endif
            </tr></thead>
            <tbody>
            @forelse($pi->lines as $line)
                <tr class="app-doc-lines__row">
                    <td class="app-doc-lines__no app-num">{{ $line->line_no }}</td>
                    <td class="app-doc-lines__pic">
                        @if($photos && $line->product?->photo)
                            <img class="app-doc-thumb" src="{{ route('products.photo', $line->product_id) }}" alt="" width="56" height="56" loading="lazy" onerror="this.style.visibility='hidden'">
                        @else
                            <span class="app-doc-thumb app-doc-thumb--empty" aria-hidden="true"><i class="bi bi-image"></i></span>
                        @endif
                    </td>
                    <td class="app-doc-lines__item">
                        <span class="app-doc-lines__lineno app-num" aria-hidden="true">{{ $line->line_no }}</span>
                        <div class="app-doc-lines__text" dir="ltr">{{ $line->itemText() }}</div>
                        @if($line->product?->code)
                            @if($productLinks)<a href="{{ route('products.show', $line->product_id) }}" class="app-doc-code app-num">{{ $line->product->code }}</a>@else<span class="app-doc-code app-num">{{ $line->product->code }}</span>@endif
                        @endif
                    </td>
                    <td class="app-num text-end" data-label="{{ __('CTN') }}">{{ PiAmounts::number($line->cartons) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Qty per carton') }}">{{ PiAmounts::number($line->qty_per_carton) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Total qty') }}">{{ PiAmounts::number($line->total_qty) }}</td>
                    <td data-label="{{ __('Unit') }}">{{ $units[$line->unit] ?? $line->unit }}</td>
                    <td class="app-num text-end" data-label="{{ __('Price') }}">{{ $money($line->unit_price) }}</td>
                    <td class="app-num text-end app-doc-lines__amount" data-label="{{ __('Amount') }}">{{ $money($line->amount) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Total CBM') }}">{{ PiAmounts::number($line->total_cbm) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Total KG') }}">{{ PiAmounts::number($line->total_kg) }}</td>
                    @if($seeSuppliers)
                        <td class="app-doc-lines__internal" data-label="{{ __('Supplier') }}">
                            <div class="app-doc-lines__supplier">{{ $line->supplier?->name ?? '—' }}</div>
                            <div class="app-num app-doc-lines__sprice">{{ $line->supplier_price !== null ? PiAmounts::money($line->supplier_price, $line->supplier_currency ?? '') : '—' }}</div>
                        </td>
                    @endif
                    @if($lineForms)
                        <td class="app-doc-lines__actions text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#line-edit-{{ $line->id }}" aria-controls="line-edit-{{ $line->id }}"
                                    aria-expanded="{{ old('form') === 'line-'.$line->id ? 'true' : 'false' }}" title="{{ __('Edit') }}"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">{{ __('Edit') }} {{ $line->line_no }}</span></button>
                            <form method="POST" action="{{ route('proforma-invoices.lines.destroy', [$pi, $line]) }}" class="d-inline" onsubmit="return confirm(@js(__('Remove this line?')));">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-light text-danger" title="{{ __('Remove') }}"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">{{ __('Remove') }} {{ $line->line_no }}</span></button>
                            </form>
                        </td>
                    @endif
                </tr>
                @if($lineForms)
                    <tr class="collapse app-doc-lines__edit {{ old('form') === 'line-'.$line->id ? 'show' : '' }}" id="line-edit-{{ $line->id }}">
                        <td colspan="{{ $columns }}">
                            <form method="POST" action="{{ route('proforma-invoices.lines.update', [$pi, $line]) }}" class="app-doc-lineform">
                                @csrf @method('PUT')
                                <div class="app-doc-lineform__title"><i class="bi bi-pencil" aria-hidden="true"></i>{{ __('Edit') }} · {{ __('No.') }} <span class="app-num">{{ $line->line_no }}</span></div>
                                @include('proforma-invoices._line-fields', ['line' => $line, 'formKey' => 'line-'.$line->id])
                                <div class="mt-3 d-flex gap-2">
                                    <button class="btn btn-sm btn-primary"><i class="bi bi-check2" aria-hidden="true"></i>{{ __('Save line') }}</button>
                                    <button type="button" class="btn btn-sm btn-light" data-bs-toggle="collapse" data-bs-target="#line-edit-{{ $line->id }}">{{ __('Close') }}</button>
                                </div>
                            </form>
                        </td>
                    </tr>
                @endif
            @empty
                <tr class="app-doc-lines__empty"><td colspan="{{ $columns }}">
                    <x-empty icon="list-ul" :title="__('No lines yet.')" :text="$lineForms ? __('Add the products this client is ordering: cartons, quantity per carton and price.') : null">
                        @if($lineForms)<x-slot:action><a href="#add-line" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add a line') }}</a></x-slot:action>
                        @endif
                    </x-empty>
                </td></tr>
            @endforelse
            </tbody>
            @if($pi->lines->isNotEmpty())
                <tfoot><tr class="app-doc-lines__total">
                    <th scope="row" colspan="5">{{ __('Total') }}</th>
                    <td class="app-num text-end" data-label="{{ __('Total qty') }}">{{ PiAmounts::number($totals->total_qty) }}</td>
                    <td></td><td></td>
                    <td class="app-num text-end app-doc-lines__amount" data-label="{{ __('Amount') }}">{{ $money($totals->amount) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Total CBM') }}">{{ PiAmounts::number($totals->total_cbm) }}</td>
                    <td class="app-num text-end" data-label="{{ __('Total KG') }}">{{ PiAmounts::number($totals->total_kg) }}</td>
                    @if($seeSuppliers)<td></td>@endif
                    @if($lineForms)<td></td>@endif
                </tr></tfoot>
            @endif
        </table>
    </div>

    <footer class="app-doc-sheet__terms"><span class="app-ltr">{{ $pi->terms_text }}</span></footer>
</article>

@if($lineForms)
    <section class="app-card mt-3 app-doc-addline" id="add-line" aria-labelledby="add-line-head">
        <div class="app-card__head border-bottom" id="add-line-head"><i class="bi bi-plus-square" aria-hidden="true"></i>{{ __('Add a line') }}</div>
        <form method="POST" action="{{ route('proforma-invoices.lines.store', $pi) }}" enctype="multipart/form-data" class="app-section__body">
            @csrf
            @include('proforma-invoices._line-fields', ['line' => null, 'formKey' => 'add'])
            <button class="btn btn-primary mt-3"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Add line') }}</button>
        </form>
    </section>
@endif

<div class="row g-3 mt-0">
    @if($payments !== null)
        <div class="col-xl-7">
            <x-section :title="__('Payments')" icon="cash-coin" flush class="h-100">
                @if($canRecordPayment)
                    <x-slot:actions><a href="{{ route('payments.create', ['kind' => 'client_receipt', 'pi' => $pi->id]) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i> {{ __('Record a payment') }}</a></x-slot:actions>
                @endif
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Revision') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th></tr></thead>
                        <tbody>
                        @forelse($payments as $payment)
                            @php $counted = $payment->kind === 'client_receipt' && $payment->currency === $pi->currency; @endphp
                            <tr @class(['app-doc-muted' => ! $counted])>
                                <td class="app-num">{{ $payment->paid_on?->toDateString() }}</td>
                                <td>@if(Route::has('payments.show'))<a href="{{ route('payments.show', $payment) }}" class="app-ltr">{{ $payment->number }}</a>@else<span class="app-ltr">{{ $payment->number }}</span>@endif
                                    @if($payment->reverses_payment_id)<span class="app-pill app-pill--skip ms-1"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>{{ __('reversal') }}</span>@endif</td>
                                <td class="app-ltr">{{ $payment->proformaInvoice?->reference() }}</td>
                                <td>{{ $payment->moneyAccount?->name }}</td>
                                <td class="app-num text-end fw-semibold">{{ PiAmounts::money($payment->signed_amount, $payment->currency) }}
                                    @unless($counted)<div class="small fw-normal">{{ __('not counted') }}</div>@endunless</td>
                            </tr>
                        @empty
                            <tr><td colspan="5"><x-empty icon="cash-coin" :title="__('No payments yet.')" /></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="app-doc-footnote"><i class="bi bi-info-circle" aria-hidden="true"></i>{{ __('Client receipts in :currency linked to any revision of this PI.', ['currency' => $pi->currency]) }}</p>
            </x-section>
        </div>
    @endif
    <div class="{{ $payments !== null ? 'col-xl-5' : 'col-12' }} app-doc-attach">
        @include('partials.attachments', ['attachable' => $pi, 'type' => 'proforma-invoices'])
    </div>
</div>
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
