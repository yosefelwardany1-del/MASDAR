@extends('layouts.app')

@section('title', __('Home'))

@php
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Route;

    $user = auth()->user();
    $today = now()->locale(app()->getLocale());
    $go = fn (string $name, $param = null) => Route::has($name) ? route($name, $param) : null;

    // What each person most often starts from here, in the order the work happens.
    $actions = array_filter([
        ['proforma_invoices.edit', 'proforma-invoices.create', 'bi-receipt', __('New PI')],
        ['goods_receipts.edit', 'goods-receipts.index', 'bi-clipboard-check', __('Receive goods')],
        ['containers.edit', 'shipments.create', 'bi-truck', __('New shipment')],
        ['payments.edit', 'payments.create', 'bi-cash-coin', __('Record a payment')],
        ['clients.edit', 'clients.create', 'bi-person-plus', __('New client')],
    ], fn ($a) => $user->can($a[0]) && Route::has($a[1]));

    $tiles = [
        ['open_pis', __('Open proforma invoices'), 'proforma-invoices.index', 'proforma_invoices.view', 'bi-receipt', ''],
        ['open_orders', __('Open supplier orders'), 'supplier-orders.index', 'supplier_orders.view', 'bi-kanban', ''],
        ['late_steps', __('Late order steps'), 'supplier-orders.index', 'supplier_orders.view', 'bi-exclamation-triangle', 'alert'],
        ['containers_moving', __('Containers on the way'), 'containers.index', 'containers.view', 'bi-boxes', 'gold'],
        ['awaiting_approval', __('Payments awaiting approval'), 'payments.index', 'payments.approve', 'bi-hourglass-split', 'alert'],
    ];
@endphp

@section('content')
    <div class="app-hello">
        <div>
            <div class="app-hello__date">{{ $today->translatedFormat(['ar' => 'l، j F Y', 'zh_CN' => 'Y年n月j日 l'][app()->getLocale()] ?? 'l, j F Y') }}</div>
            <h1 class="h4 mb-0">{{ __('Welcome, :name', ['name' => $user->name]) }}</h1>
        </div>
        @if($actions)
            <div class="d-flex flex-wrap gap-2">
                @foreach($actions as $i => [$permission, $route, $icon, $label])
                    <a href="{{ route($route) }}" class="btn {{ $i === array_key_first($actions) ? 'btn-primary' : 'btn-light' }}"><i class="bi {{ $icon }}" aria-hidden="true"></i>{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </div>

    @if($counts)
        <div class="row g-3 mb-4">
            @foreach($tiles as [$key, $label, $route, $permission, $icon, $tone])
                @can($permission)
                    @php $alert = $tone === 'alert' && $counts[$key] > 0; @endphp
                    <div class="col-6 col-lg">
                        <a href="{{ $go($route) ?? '#' }}" class="app-card app-stat text-decoration-none h-100 {{ $alert ? 'app-stat--alert' : ($tone === 'gold' ? 'app-stat--gold' : '') }}">
                            <span class="app-stat__top">
                                <span class="app-stat__icon"><i class="bi {{ $icon }}" aria-hidden="true"></i></span>
                                <i class="bi bi-arrow-right app-stat__go" aria-hidden="true"></i>
                            </span>
                            <span class="app-stat__value">{{ $counts[$key] }}</span>
                            <span class="app-stat__label">{{ $label }}</span>
                        </a>
                    </div>
                @endcan
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        @if($seesOrders)
            <div class="col-xl-6">
                <section class="app-card h-100" aria-labelledby="steps-head">
                    <div class="app-card__head border-bottom" id="steps-head">
                        <i class="bi bi-kanban" aria-hidden="true"></i>{{ __('Order steps due or late') }}
                        @if($go('supplier-orders.index'))<a href="{{ $go('supplier-orders.index') }}" class="ms-auto small fw-medium">{{ __('Open the board') }}</a>@endif
                    </div>
                    @if($steps->isEmpty())
                        <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('Nothing is due. Every order is on track.') }}</div>
                    @else
                        <ul class="app-list">
                            @foreach($steps as $row)
                                <li class="app-list__row">
                                    <div class="app-list__main">
                                        <a href="{{ $go('supplier-orders.show', $row->order_id) ?? '#' }}" class="app-list__title app-ltr">{{ $row->order_number }}</a>
                                        <div class="app-list__sub">{{ $row->step_label }}</div>
                                    </div>
                                    <span class="app-pill {{ $row->late ? 'app-pill--late' : 'app-pill--due' }}">
                                        <i class="bi {{ $row->late ? 'bi-exclamation-triangle' : 'bi-clock' }}" aria-hidden="true"></i>
                                        {{ $row->late ? __('Late') : __('Due') }} · <span class="app-num">{{ Carbon::parse($row->due_on)->format('Y-m-d') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endif

        @if($seesContainers)
            <div class="col-xl-6">
                <section class="app-card h-100" aria-labelledby="containers-head">
                    <div class="app-card__head border-bottom" id="containers-head">
                        <i class="bi bi-boxes" aria-hidden="true"></i>{{ __('Containers arriving soon or late') }}
                        @if($go('containers.index'))<a href="{{ $go('containers.index') }}" class="ms-auto small fw-medium">{{ __('Open the board') }}</a>@endif
                    </div>
                    @if($containers->isEmpty())
                        <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No container is late or arriving soon.') }}</div>
                    @else
                        <ul class="app-list">
                            @foreach($containers as $row)
                                <li class="app-list__row">
                                    <div class="app-list__main">
                                        <a href="{{ $go('containers.show', $row->container_id) ?? '#' }}" class="app-list__title app-ltr">{{ $row->container_no ?: $row->shipment_number }}</a>
                                        <div class="app-list__sub">{{ $row->status ? \App\Models\Lookup::label('container_status', $row->status) : __('No status yet') }}</div>
                                    </div>
                                    <span class="app-pill {{ $row->late ? 'app-pill--late' : 'app-pill--info' }}">
                                        <i class="bi {{ $row->late ? 'bi-exclamation-triangle' : 'bi-geo-alt' }}" aria-hidden="true"></i>
                                        {{ $row->late ? trans_choice('{1} ETA passed yesterday|[2,*] ETA passed :days days ago', abs($row->days), ['days' => abs($row->days)]) : trans_choice('{0} Arriving today|{1} Arriving tomorrow|[2,*] Arriving in :days days', $row->days, ['days' => $row->days]) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endif

        @can('crm.view')
            <div class="col-xl-6">
                <section class="app-card h-100" aria-labelledby="tasks-head">
                    <div class="app-card__head border-bottom" id="tasks-head">
                        <i class="bi bi-check2-square" aria-hidden="true"></i>{{ __('My tasks for today') }}
                        @if($go('crm.tasks.index'))<a href="{{ $go('crm.tasks.index') }}" class="ms-auto small fw-medium">{{ __('All my tasks') }}</a>@endif
                    </div>
                    @if($tasks->isEmpty())
                        <div class="app-panel-empty"><i class="bi bi-check-circle" aria-hidden="true"></i>{{ __('No tasks due today.') }}</div>
                    @else
                        <ul class="app-list">
                            @foreach($tasks as $task)
                                @php $due = Carbon::parse($task->due_on); $overdue = $due->isPast() && ! $due->isToday(); @endphp
                                <li class="app-list__row">
                                    <div class="app-list__main">
                                        <span class="app-list__title">{{ $task->title }}</span>
                                        @if($task->party_name)<div class="app-list__sub">{{ $task->party_name }}</div>@endif
                                    </div>
                                    <span class="app-pill {{ $overdue ? 'app-pill--late' : 'app-pill--due' }}">
                                        <i class="bi {{ $overdue ? 'bi-exclamation-triangle' : 'bi-calendar-event' }}" aria-hidden="true"></i>
                                        <span class="app-num">{{ $due->format('Y-m-d') }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        @endcan
    </div>

    @if(! $counts && ! $seesOrders && ! $seesContainers && ! $user->can('crm.view'))
        <div class="app-card app-empty">
            <i class="bi bi-person-lock" aria-hidden="true"></i>
            <p class="mb-0">{{ __('Nothing here yet. Ask a manager to give you a role, and your screens will appear in the menu.') }}</p>
        </div>
    @endif
@endsection
