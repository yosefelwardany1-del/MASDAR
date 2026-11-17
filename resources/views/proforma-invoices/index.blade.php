@extends('layouts.app')

@section('title', __('Proforma invoices'))

@php use App\Services\ProformaInvoices\PiAmounts; @endphp

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">{{ __('Proforma invoices') }}</h1>
    @can('proforma_invoices.edit')
        <a href="{{ route('proforma-invoices.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>{{ __('New PI') }}</a>
    @endcan
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3">
    <input type="search" name="search" value="{{ $search }}" class="form-control" style="max-inline-size:260px" placeholder="{{ __('Search by PI or PO number') }}">
    <select name="status" class="form-select" style="max-inline-size:190px" onchange="this.form.submit()">
        <option value="">{{ __('All statuses') }}</option>
        @foreach($statuses as $value)
            <option value="{{ $value }}" @selected($status === $value)>{{ \App\Models\ProformaInvoice::statusName($value) }}</option>
        @endforeach
    </select>
    <select name="client" class="form-select" style="max-inline-size:260px" onchange="this.form.submit()">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clients as $client)
            <option value="{{ $client->id }}" @selected($clientId === $client->id)>{{ $client->name }}</option>
        @endforeach
    </select>
    <button class="btn btn-light">{{ __('Search') }}</button>
</form>

<div class="app-card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr>
                <th>{{ __('PI number') }}</th><th>{{ __('Client') }}</th><th>{{ __('PO number') }}</th><th>{{ __('Date') }}</th>
                <th>{{ __('Status') }}</th><th class="text-end">{{ __('Total') }}</th><th class="text-end">{{ __('Paid') }}</th>
                <th class="text-end">{{ __('Balance due') }}</th><th></th>
            </tr></thead>
            <tbody>
            @forelse($pis as $pi)
                <tr>
                    <td><a href="{{ route('proforma-invoices.show', $pi) }}" class="fw-semibold"><span class="app-ltr">{{ $pi->reference() }}</span></a></td>
                    <td>{{ $pi->client?->name }}</td>
                    <td><span class="app-ltr">{{ $pi->po_number ?: '—' }}</span></td>
                    <td class="app-num">{{ ($pi->issued_at ?? $pi->created_at)?->toDateString() }}</td>
                    <td>@include('proforma-invoices._status', ['pi' => $pi])</td>
                    <td class="app-num text-end">{{ PiAmounts::money($pi->total_amount, $pi->currency) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::money($pi->paid_amount, $pi->currency) }}</td>
                    <td class="app-num text-end">{{ PiAmounts::money(PiAmounts::subtract($pi->total_amount, $pi->paid_amount), $pi->currency) }}</td>
                    <td class="text-end text-nowrap">
                        <a href="{{ route('proforma-invoices.show', $pi) }}" class="btn btn-sm btn-light" title="{{ __('View') }}"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('proforma-invoices.print', $pi) }}" class="btn btn-sm btn-light" title="{{ __('Print') }}" target="_blank" rel="noopener"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="app-empty">{{ __('No proforma invoices found.') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($pis->hasPages())<div class="p-3">{{ $pis->links() }}</div>@endif
</div>
@endsection
