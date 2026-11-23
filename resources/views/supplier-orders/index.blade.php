@extends('layouts.app')

@section('title', __('Purchase orders'))

@section('content')
@php
    $canSeePis = auth()->user()->can('proforma_invoices.view') && Route::has('proforma-invoices.show');
    $canSeeSupplierPage = auth()->user()->can('suppliers.view') && Route::has('suppliers.show');
    $stepList = $steps->values();
    $stepCount = $stepList->count();
    $comma = app()->getLocale() === 'ar' ? '، ' : ', ';
    $filtered = collect($filters)->except('status')->filter()->isNotEmpty() || ($filters['status'] ?? 'open') !== 'open';

    // Per order: how many steps are settled, which are late, and the step that comes next (the first one not settled).
    $rows = [];
    foreach ($orders as $order) {
        $own = $states[$order->id] ?? [];
        $settled = collect($own)->filter(fn ($s) => $s->isSettled())->count();
        $late = collect($own)->filter(fn ($s) => $s->state === 'late')->count();
        $next = $order->status === 'open' ? $stepList->first(fn ($step) => ! ($own[$step->id] ?? null)?->isSettled()) : null;
        $rows[$order->id] = [
            'settled' => $settled,
            'late' => $late,
            'isLate' => $order->is_late || $late > 0,
            'next' => $next,
            'nextState' => $next ? $own[$next->id] : null,
        ];
    }
    $nextLine = function ($row) {
        if (! $row['next']) {
            return null;
        }
        $state = $row['nextState'];
        $date = $state->dueOn?->format('Y-m-d');

        return ['name' => $row['next']->displayName(), 'state' => $state->state, 'date' => $date];
    };
    $stepperFor = function ($order) use ($stepList, $states, $rows) {
        $own = $states[$order->id] ?? [];
        $nextId = $rows[$order->id]['next']?->id;

        return $stepList->map(function ($step) use ($own, $nextId) {
            $s = $own[$step->id];
            $state = match ($s->state) {
                'done' => 'done',
                'skipped' => 'skip',
                'late' => 'late',
                'due' => 'due',
                default => $step->id === $nextId ? 'current' : 'todo',
            };

            return ['label' => $step->displayName(), 'state' => $state, 'date' => $s->date()?->format('Y-m-d')];
        })->all();
    };
@endphp

@php $fromIssued = \App\Models\BusinessSetting::current()->orders_from_issued_pi; @endphp
<x-page-header :title="__('Purchase orders')" :subtitle="$fromIssued
    ? __('One purchase order per factory, made from an issued PI, followed step by step: one row per order, one column per step. Late rows come first.')
    : __('One purchase order per factory, made from a confirmed PI, followed step by step: one row per order, one column per step. Late rows come first.')">
    <x-slot:meta>
        <span class="app-pill"><i class="bi bi-kanban" aria-hidden="true"></i>{{ trans_choice('{0} No orders|{1} :count order|[2,*] :count orders', $orders->total(), ['count' => $orders->total()]) }}</span>
        @if($lateCount > 0)
            <a href="{{ route('supplier-orders.index', ['late' => 1] + array_filter($filters)) }}" class="app-pill app-pill--late text-decoration-none">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{1} :count late|[2,*] :count late', $lateCount, ['count' => $lateCount]) }}
            </a>
        @else
            <x-status tone="done">{{ __('Nothing late') }}</x-status>
        @endif
    </x-slot:meta>
    <x-slot:actions>
        @if($seeSuppliers)
            @can('supplier_orders.edit')
                <a href="{{ route('supplier-orders.create') }}" class="btn btn-primary"><i class="bi bi-cart-plus" aria-hidden="true"></i>{{ __('New purchase orders') }}</a>
            @endcan
        @endif
        <a href="{{ route('supplier-orders.evaluation') }}" class="btn btn-light"><i class="bi bi-bar-chart" aria-hidden="true"></i>{{ __('Supplier evaluation') }}</a>
        @can('goods_receipts.view')
            <a href="{{ route('goods-receipts.index') }}" class="btn btn-light"><i class="bi bi-clipboard-check" aria-hidden="true"></i>{{ __('Goods receipt & inspection') }}</a>
        @endcan
    </x-slot:actions>
</x-page-header>

<form method="GET" class="d-flex flex-wrap gap-2 mb-3 align-items-center app-fu-filters" role="search" aria-label="{{ __('Filter the board') }}">
    <div class="app-fu-search">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="search" value="{{ $filters['search'] ?? '' }}" class="form-control" placeholder="{{ __('Order number') }}" aria-label="{{ __('Order number') }}" dir="ltr">
    </div>
    <select name="status" class="form-select" aria-label="{{ __('Status') }}">
        @foreach(['open' => __('Open'), 'completed' => __('Completed'), 'cancelled' => __('Cancelled'), 'all' => __('All statuses')] as $value => $label)
            <option value="{{ $value }}" @selected(($filters['status'] ?? 'open') === $value)>{{ $label }}</option>
        @endforeach
    </select>
    @if($seeSuppliers)
        <select name="supplier" class="form-select" aria-label="{{ __('Supplier') }}">
            <option value="">{{ __('All suppliers') }}</option>
            @foreach($suppliers as $s)
                <option value="{{ $s->id }}" @selected((int) ($filters['supplier'] ?? 0) === $s->id)>{{ $s->name }}</option>
            @endforeach
        </select>
    @endif
    <select name="client" class="form-select" aria-label="{{ __('Client') }}">
        <option value="">{{ __('All clients') }}</option>
        @foreach($clients as $c)
            <option value="{{ $c->id }}" @selected((int) ($filters['client'] ?? 0) === $c->id)>{{ $c->name }}</option>
        @endforeach
    </select>
    <select name="merchandiser" class="form-select" aria-label="{{ __('Merchandiser') }}">
        <option value="">{{ __('All merchandisers') }}</option>
        @foreach($merchandisers as $m)
            <option value="{{ $m->id }}" @selected((int) ($filters['merchandiser'] ?? 0) === $m->id)>{{ $m->name }}</option>
        @endforeach
    </select>
    <div class="form-check form-switch ms-1">
        <input class="form-check-input" type="checkbox" role="switch" name="late" value="1" id="late-only" @checked(! empty($filters['late']))>
        <label class="form-check-label" for="late-only">{{ __('Late only') }}</label>
    </div>
    <button class="btn btn-primary"><i class="bi bi-funnel" aria-hidden="true"></i>{{ __('Filter') }}</button>
    @if($filtered)
        <a href="{{ route('supplier-orders.index') }}" class="btn btn-link btn-sm">{{ __('Clear filters') }}</a>
    @endif
</form>

<ul class="app-fu-legend" aria-label="{{ __('Legend') }}">
    <li class="app-fu-legend__title" aria-hidden="true">{{ __('Legend') }}:</li>
    <li><span class="app-pill app-pill--done app-fu-dot" aria-hidden="true"><i class="bi bi-check-lg"></i></span>{{ __('Done') }}</li>
    <li><span class="app-pill app-pill--due app-fu-dot" aria-hidden="true"><i class="bi bi-clock"></i></span>{{ __('Due soon') }}</li>
    <li><span class="app-pill app-pill--late app-fu-dot" aria-hidden="true"><i class="bi bi-exclamation-lg"></i></span>{{ __('Late') }}</li>
    <li><span class="app-pill app-pill--skip app-fu-dot" aria-hidden="true"><i class="bi bi-dash-lg"></i></span>{{ __('Not applicable') }}</li>
    <li><span class="app-pill app-fu-dot" aria-hidden="true"></span>{{ __('Not done yet') }}</li>
    <li><span class="app-pill app-fu-dot is-next" aria-hidden="true"></span>{{ __('Next step') }}</li>
    <li class="app-fu-legend__row">{{ __('Order with a late step') }}</li>
    <li class="d-none d-md-inline-flex text-muted">{{ __('Point at a step to see its date.') }}</li>
</ul>

@if($waiting->isNotEmpty())
    <x-section :title="__('Sales orders waiting for purchase orders')" icon="cart-plus" class="mb-3">
        <ul class="list-unstyled mb-0 d-flex flex-wrap gap-2" aria-label="{{ __('Sales orders waiting for purchase orders') }}">
            @foreach($waiting as $pi)
                <li><a href="{{ route('supplier-orders.create', ['pi' => $pi->id]) }}" class="btn btn-light btn-sm"><i class="bi bi-cart-plus" aria-hidden="true"></i><span class="app-ltr">{{ $pi->reference() }}</span> · <bdi>{{ $pi->client?->name }}</bdi></a></li>
            @endforeach
            @if($waitingCount > $waiting->count())
                <li><a href="{{ route('supplier-orders.create') }}" class="btn btn-link btn-sm">{{ __('See all :count', ['count' => $waitingCount]) }}</a></li>
            @endif
        </ul>
    </x-section>
@endif

@if($orders->isEmpty())
    <div class="app-card">
        <x-empty icon="kanban" :title="__('No supplier orders found.')" :text="$filtered ? __('Try other filters, or clear them to see every open order.') : ($fromIssued ? __('Purchase orders are made from an issued PI and appear here, one row each.') : __('Purchase orders are made from a confirmed PI and appear here, one row each.'))">
            @if($filtered)
                <x-slot:action><a href="{{ route('supplier-orders.index') }}" class="btn btn-light btn-sm">{{ __('Clear filters') }}</a></x-slot:action>
            @endif
        </x-empty>
    </div>
@else
    {{-- Phone: one card per order, its steps as a compact stepper. --}}
    <ul class="app-fu-cards d-md-none" aria-label="{{ __('Supplier orders') }}">
        @foreach($orders as $order)
            @php $row = $rows[$order->id]; $next = $nextLine($row); @endphp
            <li class="app-fu-card @if($row['isLate']) is-late @endif">
                <div class="app-fu-card__top">
                    <a href="{{ route('supplier-orders.show', $order) }}" class="app-fu-order__no"><span class="app-ltr">{{ $order->number }}</span></a>
                    @if($row['isLate'])
                        <span class="app-pill app-pill--late" title="{{ __('Has late steps') }}"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{0} Late|{1} :count late step|[2,*] :count late steps', $row['late'], ['count' => $row['late']]) }}</span>
                    @endif
                    @if($order->status !== 'open')@include('supplier-orders._status', ['status' => $order->status])@endif
                    <span class="app-fu-card__aside app-fu-sub">
                        {{ __('Delivery') }} <span class="app-num app-fu-strong">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                        @isset($original[$order->id])<i class="bi bi-calendar-x text-warning" aria-label="{{ __('Delivery date moved') }}"></i>@endisset
                    </span>
                </div>
                <div class="app-fu-sub">
                    @if($seeSuppliers)<bdi>{{ $order->supplier?->name }}</bdi><span class="app-fu-sep" aria-hidden="true">·</span>@endif
                    <bdi>{{ collect($orderClients[$order->id] ?? [])->pluck('client_name')->unique()->join($comma) ?: '—' }}</bdi>
                </div>
                <x-stepper :steps="$stepperFor($order)" compact :label="__('Steps of :number', ['number' => $order->number])" />
                <div class="app-fu-card__foot">
                    <span class="app-fu-sub"><span class="app-num app-fu-strong">{{ $row['settled'] }}/{{ $stepCount }}</span> {{ __('steps done') }}</span>
                    @if($next)
                        <span class="app-fu-next m-0">
                            <span class="app-fu-next__label">{{ __('Next') }}:</span> <span class="app-fu-next__name">{{ $next['name'] }}</span>
                        </span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>

    {{-- Tablet and desktop: the board, one column per step; the order column stays in view. --}}
    <div class="app-card d-none d-md-block">
        <div class="table-responsive app-board app-fu-board">
            <table class="table table-sm table-hover align-middle mb-0">
                <caption class="visually-hidden">{{ __('Purchase orders') }}</caption>
                <thead><tr>
                    <th scope="col" class="app-fu-ordercell">{{ __('Order no.') }} · {{ __('Factory / supplier') }}</th>
                    <th scope="col" class="app-fu-progresscell">{{ __('Delivery date') }} · {{ __('Progress') }}</th>
                    @foreach($stepList as $i => $step)
                        <th scope="col" class="app-fu-stephead" title="{{ $step->displayName() }}">
                            <span class="app-fu-stephead__no" aria-hidden="true">{{ $i + 1 }}</span>
                            <span class="app-fu-stephead__name">{{ $step->displayName() }}</span>
                        </th>
                    @endforeach
                </tr></thead>
                <tbody>
                @foreach($orders as $order)
                    @php $row = $rows[$order->id]; $next = $nextLine($row); $own = $states[$order->id]; @endphp
                    <tr @class(['app-fu-row--late' => $row['isLate']])>
                        <th scope="row" class="app-fu-ordercell fw-normal">
                            <div class="app-fu-order">
                                <div class="app-fu-order__top">
                                    <a href="{{ route('supplier-orders.show', $order) }}" class="app-fu-order__no"><span class="app-ltr">{{ $order->number }}</span></a>
                                    @if($row['isLate'])
                                        <span class="app-pill app-pill--late" title="{{ __('Has late steps') }}"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>{{ trans_choice('{0} Late|{1} :count late|[2,*] :count late', $row['late'], ['count' => $row['late']]) }}</span>
                                    @endif
                                    @if($order->status !== 'open')@include('supplier-orders._status', ['status' => $order->status])@endif
                                </div>
                                <div class="app-fu-order__line app-fu-order__line--names">
                                    @if($seeSuppliers)
                                        <i class="bi bi-buildings" aria-hidden="true"></i>
                                        @if($canSeeSupplierPage)
                                            <a href="{{ route('suppliers.show', $order->supplier_id) }}"><bdi>{{ $order->supplier?->name }}</bdi></a>
                                        @else
                                            <bdi>{{ $order->supplier?->name }}</bdi>
                                        @endif
                                    @else
                                        <span class="visually-hidden">{{ __('Factory / supplier') }}:</span> —
                                    @endif
                                </div>
                                <div class="app-fu-order__line app-fu-order__line--names" title="{{ collect($orderClients[$order->id] ?? [])->map(fn ($c) => $c->pi_reference.' '.$c->client_name)->join(' · ') }}">
                                    <i class="bi bi-person" aria-hidden="true"></i>
                                    @forelse($orderClients[$order->id] ?? [] as $client)
                                        @if(! $loop->first)<span class="app-fu-sep" aria-hidden="true">·</span>@endif
                                        @if($canSeePis)
                                            <a href="{{ route('proforma-invoices.show', $client->pi_id) }}" class="app-ltr">{{ $client->pi_reference }}</a>
                                        @else
                                            <span class="app-ltr">{{ $client->pi_reference }}</span>
                                        @endif
                                        <bdi>{{ $client->client_name }}</bdi>
                                    @empty
                                        —
                                    @endforelse
                                </div>
                                <div class="app-fu-order__line" title="{{ __('Confirmation date') }}: {{ $order->confirmation_date?->format('Y-m-d') ?? '—' }}{{ $order->merchandiser ? ' · '.__('Merchandiser').': '.$order->merchandiser->name : '' }}">
                                    {{ __('Confirmed') }} <span class="app-num">{{ $order->confirmation_date?->format('Y-m-d') ?? '—' }}</span>
                                    @if($order->merchandiser)<span class="app-fu-sep" aria-hidden="true">·</span><i class="bi bi-person-badge" aria-hidden="true"></i><span class="visually-hidden">{{ __('Merchandiser') }}:</span> <bdi>{{ $order->merchandiser->name }}</bdi>@endif
                                </div>
                            </div>
                        </th>
                        <td class="app-fu-progresscell">
                            <div class="app-fu-delivery">
                                <span class="app-fu-sub">{{ __('Delivery') }}</span>
                                <span class="app-num app-fu-strong">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</span>
                                @isset($original[$order->id])
                                    <span class="app-pill app-pill--due"
                                          title="{{ __('Moved: first promised :date, changed :count times', ['date' => $original[$order->id]['date']?->format('Y-m-d') ?? '—', 'count' => $original[$order->id]['changes']]) }}"
                                          data-bs-toggle="tooltip"><i class="bi bi-calendar-x" aria-hidden="true"></i>{{ __('Moved') }}<span class="visually-hidden">: {{ __('Moved: first promised :date, changed :count times', ['date' => $original[$order->id]['date']?->format('Y-m-d') ?? '—', 'count' => $original[$order->id]['changes']]) }}</span></span>
                                @endisset
                            </div>
                            <x-progress class="app-fu-progress" :value="$row['settled']" :max="max($stepCount, 1)" thin
                                        :label="__('Steps done')" :caption="$row['settled'].' / '.$stepCount"
                                        :tone="$row['isLate'] ? 'late' : ($row['settled'] === $stepCount ? 'done' : 'primary')" />
                            @if($next)
                                <div class="app-fu-next">
                                    <span class="app-fu-next__label">{{ __('Next') }}:</span>
                                    <span class="app-fu-next__name">{{ $next['name'] }}</span>
                                    @if($next['date'])
                                        <span class="app-fu-next__due is-{{ in_array($next['state'], ['late', 'due'], true) ? $next['state'] : 'open' }}">
                                            @if($next['state'] === 'late')<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span class="visually-hidden">{{ __('Late') }}</span>@elseif($next['state'] === 'due')<i class="bi bi-clock" aria-hidden="true"></i><span class="visually-hidden">{{ __('Due soon') }}</span>@endif
                                            <span class="app-num">{{ $next['date'] }}</span>
                                        </span>
                                    @endif
                                </div>
                            @elseif($order->status === 'open' && $stepCount > 0)
                                <div class="app-fu-next is-finished"><i class="bi bi-check2-all" aria-hidden="true"></i> {{ __('Every step is settled') }}</div>
                            @endif
                        </td>
                        @foreach($stepList as $i => $step)
                            @php $state = $own[$step->id]; $prev = $i > 0 ? $own[$stepList[$i - 1]->id] : null; @endphp
                            <td @class(['app-fu-trk', 'is-first' => $i === 0, 'is-last' => $i === $stepCount - 1, 'is-settled' => $state->isSettled(), 'from-settled' => $prev?->isSettled()])>
                                @include('supplier-orders._dot', ['state' => $state, 'step' => $step, 'next' => $row['next']?->id === $step->id])
                            </td>
                        @endforeach
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

@if($orders->hasPages())<div class="mt-3">{{ $orders->links() }}</div>@endif
@endsection

@push('scripts')
<script>
    // Step dates on hover and focus, without the browser's slow native tooltip.
    document.addEventListener('DOMContentLoaded', () => {
        if (window.bootstrap) new window.bootstrap.Tooltip(document.body, { selector: '[data-bs-toggle="tooltip"]' });
    });
</script>
@endpush
