@extends('layouts.app')

@section('title', __('Home'))

@php use Illuminate\Support\Facades\Route; @endphp

@section('content')
    <h1 class="h4 mb-3">{{ __('Welcome, :name', ['name' => auth()->user()->name]) }}</h1>

    @if($counts)
        <div class="row g-3 mb-4">
            @foreach([
                ['open_pis', __('Open proforma invoices'), 'proforma-invoices.index', 'proforma_invoices.view'],
                ['open_orders', __('Open supplier orders'), 'supplier-orders.index', 'supplier_orders.view'],
                ['late_steps', __('Late order steps'), 'supplier-orders.index', 'supplier_orders.view'],
                ['containers_moving', __('Containers on the way'), 'containers.index', 'containers.view'],
                ['awaiting_approval', __('Payments awaiting approval'), 'payments.index', 'payments.approve'],
            ] as [$key, $label, $route, $permission])
                @can($permission)
                    <div class="col-6 col-lg">
                        <a href="{{ Route::has($route) ? route($route) : '#' }}" class="app-card app-stat d-block text-decoration-none h-100">
                            <div class="app-stat__value {{ $key === 'late_steps' && $counts[$key] > 0 ? 'text-danger' : '' }}">{{ $counts[$key] }}</div>
                            <div class="app-stat__label">{{ $label }}</div>
                        </a>
                    </div>
                @endcan
            @endforeach
        </div>
    @endif

    <div class="row g-3">
        @if($seesOrders)
            <div class="col-lg-6">
                <div class="app-card h-100">
                    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-kanban me-2"></i>{{ __('Order steps due or late') }}</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <tbody>
                            @forelse($steps as $row)
                                <tr>
                                    <td><a href="{{ Route::has('supplier-orders.show') ? route('supplier-orders.show', $row->order_id) : '#' }}" class="app-ltr">{{ $row->order_number }}</a></td>
                                    <td>{{ $row->step_label }}</td>
                                    <td class="text-end"><span class="app-pill {{ $row->late ? 'app-pill--late' : 'app-pill--due' }}"><span class="app-num">{{ \Illuminate\Support\Carbon::parse($row->due_on)->format('Y-m-d') }}</span></span></td>
                                </tr>
                            @empty
                                <tr><td class="app-empty py-4">{{ __('Nothing is due. Every order is on track.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @if($seesContainers)
            <div class="col-lg-6">
                <div class="app-card h-100">
                    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-boxes me-2"></i>{{ __('Containers arriving soon or late') }}</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <tbody>
                            @forelse($containers as $row)
                                <tr>
                                    <td><a href="{{ Route::has('containers.show') ? route('containers.show', $row->container_id) : '#' }}" class="app-ltr">{{ $row->container_no ?: $row->shipment_number }}</a></td>
                                    <td>{{ $row->status ? \App\Models\Lookup::label('container_status', $row->status) : __('No status yet') }}</td>
                                    <td class="text-end">
                                        <span class="app-pill {{ $row->late ? 'app-pill--late' : 'app-pill--due' }}">
                                            {{ $row->late ? __('ETA passed :days days ago', ['days' => abs($row->days)]) : __('Arriving in :days days', ['days' => $row->days]) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="app-empty py-4">{{ __('No container is late or arriving soon.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @endif

        @can('crm.view')
            <div class="col-lg-6">
                <div class="app-card h-100">
                    <div class="p-3 border-bottom fw-semibold"><i class="bi bi-check2-square me-2"></i>{{ __('My tasks for today') }}</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <tbody>
                            @forelse($tasks as $task)
                                <tr>
                                    <td>{{ $task->title }} @if($task->party_name)<span class="text-muted small">· {{ $task->party_name }}</span>@endif</td>
                                    <td class="text-end"><span class="app-pill {{ \Illuminate\Support\Carbon::parse($task->due_on)->isPast() && ! \Illuminate\Support\Carbon::parse($task->due_on)->isToday() ? 'app-pill--late' : 'app-pill--due' }}"><span class="app-num">{{ \Illuminate\Support\Carbon::parse($task->due_on)->format('Y-m-d') }}</span></span></td>
                                </tr>
                            @empty
                                <tr><td class="app-empty py-4">{{ __('No tasks due today.') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(Route::has('crm.tasks.index'))<div class="p-2 text-end"><a href="{{ route('crm.tasks.index') }}" class="small">{{ __('All my tasks') }}</a></div>@endif
                </div>
            </div>
        @endcan
    </div>

    @if(! $counts && ! $seesOrders && ! $seesContainers && ! auth()->user()->can('crm.view'))
        <div class="app-card app-empty">
            <i class="bi bi-buildings"></i>
            <p class="mb-0">{{ __('Nothing here yet. Ask a manager to give you a role, and your screens will appear in the menu.') }}</p>
        </div>
    @endif
@endsection
