{{-- A party's documents, read-only, each list only for a user who may view its screen
     (PartyController::show). Links appear once the screen that shows them exists. --}}
@php use Illuminate\Support\Facades\Route as RouteFacade; @endphp
@foreach($related as $what => $rows)
    @switch($what)
        @case('proforma_invoices')
            @php $link = RouteFacade::has('proforma-invoices.show'); @endphp
            <x-section :title="__('Proforma invoices')" icon="receipt" flush class="mb-3">
                @if(RouteFacade::has('proforma-invoices.index'))
                    <x-slot:actions><a href="{{ route('proforma-invoices.index', ['client' => $party->id]) }}">{{ __('Open the list') }}</a></x-slot:actions>
                @endif
                @if($rows->isEmpty())
                    <div class="app-panel-empty app-panel-empty--muted"><i class="bi bi-receipt" aria-hidden="true"></i>{{ __('None yet.') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Revision') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Currency') }}</th><th scope="col">{{ __('Issued on') }}</th></tr></thead>
                            <tbody>
                            @foreach($rows as $pi)
                                <tr>
                                    <td class="app-num">@if($link)<a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr">{{ $pi->pi_number }}</a>@else<span class="app-ltr">{{ $pi->pi_number }}</span>@endif</td>
                                    <td class="app-num">{{ $pi->revision }}</td>
                                    <td>@include('proforma-invoices._status', ['pi' => $pi])</td>
                                    <td>{{ $pi->currency }}</td>
                                    <td class="app-num">{{ $pi->issued_at?->format('Y-m-d') ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-section>
            @break
        @case('supplier_orders')
            @php $link = RouteFacade::has('supplier-orders.show'); @endphp
            <x-section :title="__('Supplier orders')" icon="kanban" flush class="mb-3">
                @if($rows->isEmpty())
                    <div class="app-panel-empty app-panel-empty--muted"><i class="bi bi-kanban" aria-hidden="true"></i>{{ __('None yet.') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Status') }}</th><th scope="col">{{ __('Currency') }}</th><th scope="col">{{ __('Delivery date') }}</th></tr></thead>
                            <tbody>
                            @foreach($rows as $order)
                                <tr>
                                    <td class="app-num">@if($link)<a href="{{ route('supplier-orders.show', $order) }}" class="app-ltr">{{ $order->number }}</a>@else<span class="app-ltr">{{ $order->number }}</span>@endif</td>
                                    <td><span class="app-pill">{{ __(ucfirst($order->status)) }}</span></td>
                                    <td>{{ $order->currency }}</td>
                                    <td class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-section>
            @break
        @case('shipments')
            @php $link = RouteFacade::has('containers.show'); $shipmentLink = RouteFacade::has('shipments.show'); @endphp
            <x-section :title="__('Shipments')" icon="truck" flush class="mb-3">
                @if($rows->isEmpty())
                    <div class="app-panel-empty app-panel-empty--muted"><i class="bi bi-truck" aria-hidden="true"></i>{{ __('None yet.') }}</div>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead><tr><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Booking no.') }}</th><th scope="col">{{ __('ETD') }}</th><th scope="col">{{ __('ETA') }}</th><th scope="col">{{ __('Containers') }}</th></tr></thead>
                            <tbody>
                            @foreach($rows as $shipment)
                                <tr>
                                    <td class="app-num">@if($shipmentLink)<a href="{{ route('shipments.show', $shipment) }}" class="app-ltr">{{ $shipment->number }}</a>@else<span class="app-ltr">{{ $shipment->number }}</span>@endif</td>
                                    <td class="app-num">{{ $shipment->booking_no ?: '—' }}</td>
                                    <td class="app-num">{{ $shipment->etd?->format('Y-m-d') ?? '—' }}</td>
                                    <td class="app-num">{{ $shipment->eta?->format('Y-m-d') ?? '—' }}</td>
                                    <td>
                                        @forelse($shipment->containers as $container)
                                            @if($link)<a href="{{ route('containers.show', $container) }}" class="app-doc-code me-1">{{ $container->container_no ?: '#'.$container->id }}</a>@else<span class="app-doc-code me-1">{{ $container->container_no ?: '#'.$container->id }}</span>@endif
                                        @empty — @endforelse
                                    </td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-section>
            @break
    @endswitch
@endforeach
