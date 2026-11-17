@extends('layouts.app')

@section('title', __('Proforma invoices'))

@php
    use App\Models\ProformaInvoice;
    use App\Services\ProformaInvoices\PiAmounts;

    $filtered = $search !== '' || $status || $clientId;
    // One click per status; the search and the client stay as they are.
    $statusUrl = fn (?string $value) => route('proforma-invoices.index', array_filter(['search' => $search ?: null, 'client' => $clientId, 'status' => $value]));
@endphp

@section('content')
<x-page-header :title="__('Proforma invoices')" :subtitle="__('Each PI\'s current revision, with what the client has paid and what is still due.')">
    @can('proforma_invoices.edit')
        <x-slot:actions>
            <a href="{{ route('proforma-invoices.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New PI') }}</a>
        </x-slot:actions>
    @endcan
</x-page-header>

<nav class="app-doc-chips" aria-label="{{ __('Status') }}">
    <a href="{{ $statusUrl(null) }}" class="app-doc-chip {{ $status ? '' : 'is-active' }}" @unless($status) aria-current="page" @endunless>{{ __('All statuses') }}</a>
    @foreach($statuses as $value)
        <a href="{{ $statusUrl($value) }}" class="app-doc-chip {{ $status === $value ? 'is-active' : '' }}" @if($status === $value) aria-current="page" @endif>{{ ProformaInvoice::statusName($value) }}</a>
    @endforeach
</nav>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3" role="search">
    @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
    <div class="app-doc-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label class="visually-hidden" for="pi-search">{{ __('Search by PI or PO number') }}</label>
        <input type="search" id="pi-search" name="search" value="{{ $search }}" class="form-control" placeholder="{{ __('Search by PI or PO number') }}">
    </div>
    <label class="visually-hidden" for="pi-client">{{ __('Client') }}</label>
    <select id="pi-client" name="client" class="form-select app-doc-filter" onchange="this.form.submit()">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clients as $client)
            <option value="{{ $client->id }}" @selected($clientId === $client->id)>{{ $client->name }}</option>
        @endforeach
    </select>
    <button class="btn btn-light"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Search') }}</button>
    @if($filtered)
        <a href="{{ route('proforma-invoices.index') }}" class="btn btn-link"><i class="bi bi-x-lg" aria-hidden="true"></i>{{ __('Clear filters') }}</a>
    @endif
</form>

<div class="app-card">
    @if($pis->isEmpty())
        <x-empty icon="receipt" :title="__('No proforma invoices found.')"
                 :text="$filtered ? __('Nothing matches these filters. Clear them to see every PI.') : __('A PI starts as a draft: pick the client, add the lines, then issue it.')">
            <x-slot:action>
                @if($filtered)
                    <a href="{{ route('proforma-invoices.index') }}" class="btn btn-light btn-sm">{{ __('Clear filters') }}</a>
                @else
                    @can('proforma_invoices.edit')<a href="{{ route('proforma-invoices.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('New PI') }}</a>@endcan
                @endif
            </x-slot:action>
        </x-empty>
    @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 app-doc-list">
                <thead><tr>
                    <th scope="col">{{ __('PI number') }}</th><th scope="col">{{ __('Client') }}</th><th scope="col">{{ __('PO number') }}</th><th scope="col">{{ __('Date') }}</th>
                    <th scope="col">{{ __('Status') }}</th><th scope="col" class="text-end">{{ __('Total') }}</th><th scope="col" class="text-end">{{ __('Paid') }}</th>
                    <th scope="col" class="text-end">{{ __('Balance due') }}</th><th scope="col"><span class="visually-hidden">{{ __('Actions') }}</span></th>
                </tr></thead>
                <tbody>
                @foreach($pis as $pi)
                    @php
                        $balance = PiAmounts::subtract($pi->total_amount, $pi->paid_amount);
                        $hasTotal = bccomp((string) $pi->total_amount, '0', 4) > 0;
                        $settled = $hasTotal && bccomp($balance, '0', 4) <= 0;
                        $open = in_array($pi->status, ['issued', 'accepted', 'confirmed'], true);
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('proforma-invoices.show', $pi) }}" class="app-doc-list__ref"><span class="app-ltr">{{ $pi->reference() }}</span></a>
                        </td>
                        <td class="app-doc-list__client"><bdi>{{ $pi->client?->name }}</bdi>
                            @if($pi->client?->code)<div class="app-doc-list__sub app-num">{{ $pi->client->code }}</div>@endif</td>
                        <td><span class="app-ltr">{{ $pi->po_number ?: '—' }}</span></td>
                        <td class="app-num text-nowrap">{{ ($pi->issued_at ?? $pi->created_at)?->toDateString() }}</td>
                        <td>@include('proforma-invoices._status', ['pi' => $pi])</td>
                        <td class="app-num text-end text-nowrap fw-semibold">{{ PiAmounts::money($pi->total_amount, $pi->currency) }}</td>
                        <td class="app-num text-end text-nowrap app-doc-list__paid">
                            {{ PiAmounts::money($pi->paid_amount, $pi->currency) }}
                            @if($hasTotal && $open)<x-progress :value="(float) $pi->paid_amount" :max="(float) $pi->total_amount" :tone="$settled ? 'done' : 'gold'" thin :label="__('Paid against total')" class="app-doc-list__bar" />@endif
                        </td>
                        <td class="app-num text-end text-nowrap">
                            @if($settled)
                                <span class="app-pill app-pill--done"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Paid in full') }}</span>
                                <span class="visually-hidden">{{ PiAmounts::money($balance, $pi->currency) }}</span>
                            @else
                                <span class="{{ $open ? 'fw-semibold' : 'text-muted' }}">{{ PiAmounts::money($balance, $pi->currency) }}</span>
                            @endif
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="{{ route('proforma-invoices.print', $pi) }}" class="btn btn-sm btn-light" title="{{ __('Print') }}" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i><span class="visually-hidden">{{ __('Print') }} {{ $pi->reference() }}</span></a>
                            <a href="{{ route('proforma-invoices.show', $pi) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-chevron-{{ \App\Http\Middleware\SetUserLocale::isRtl() ? 'left' : 'right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ __('View') }} {{ $pi->reference() }}</span></a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @if($pis->hasPages())<div class="p-3 border-top">{{ $pis->links() }}</div>@endif
</div>
@endsection
