@extends('layouts.app')

@section('title', __('Expense claims'))

@php use App\Services\Accounting\Decimal; @endphp

@section('content')
<x-page-header :title="__('Expense claims')" :eyebrow="__('Finance')" :subtitle="__('Staff claims for money spent for MASDAR: approved by a manager, booked from the posting queue, paid back as a payment.')" />

<nav class="app-doc-chips mb-3" aria-label="{{ __('Status') }}">
    @foreach(['submitted' => __('Waiting for approval'), 'approved' => __('Approved'), 'returned' => __('Returned for changes'), 'rejected' => __('Rejected'), 'draft' => __('Draft'), 'all' => __('All')] as $value => $label)
        <a class="app-doc-chip {{ $status === $value ? 'is-active' : '' }}" href="{{ route('expense-claims.index', ['status' => $value]) }}" @if($status === $value) aria-current="page" @endif>{{ $label }}</a>
    @endforeach
</nav>

<x-section :title="__('Claims')" icon="receipt-cutoff" flush>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th scope="col">{{ __('Claim') }}</th><th scope="col">{{ __('Claimant') }}</th><th scope="col">{{ __('Title') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th><th scope="col">{{ __('Status') }}</th></tr></thead>
            <tbody>
            @forelse($claims as $c)
                <tr>
                    <td><a href="{{ route('expense-claims.show', $c) }}" class="fw-semibold app-ltr">{{ $c->number }}</a></td>
                    <td>{{ $c->user?->name }}</td>
                    <td dir="auto">{{ $c->title }}</td>
                    <td class="text-end"><span class="app-num">{{ Decimal::format((string) ($c->lines_sum_amount ?? 0)) }}</span> <span class="app-unit">{{ $c->currency }}</span></td>
                    <td>@include('expense-claims._status', ['claim' => $c, 'paidCount' => $c->paid_count])</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-0"><x-empty icon="receipt-cutoff" :title="__('No claims here.')" /></td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</x-section>
<div class="mt-3">{{ $claims->links() }}</div>
@endsection
