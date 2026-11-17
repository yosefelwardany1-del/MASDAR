{{-- A party's documents, read-only, each list only for a user who may view its screen
     (PartyController::show). Links appear once the screen that shows them exists. --}}
@php use Illuminate\Support\Facades\Route as RouteFacade; @endphp
@foreach($related as $what => $rows)
    <div class="app-card p-4 mt-3">
        @switch($what)
            @case('proforma_invoices')
                <h2 class="h6 mb-3">{{ __('Proforma invoices') }}</h2>
                @php $link = RouteFacade::has('proforma-invoices.show'); @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Revision') }}</th><th>{{ __('Status') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Issued on') }}</th></tr></thead>
                        <tbody>
                        @forelse($rows as $pi)
                            <tr>
                                <td class="app-num">@if($link)<a href="{{ route('proforma-invoices.show', $pi) }}">{{ $pi->pi_number }}</a>@else{{ $pi->pi_number }}@endif</td>
                                <td class="app-num">{{ $pi->revision }}</td>
                                <td><span class="app-pill">{{ __(ucfirst($pi->status)) }}</span></td>
                                <td>{{ $pi->currency }}</td>
                                <td class="app-num">{{ $pi->issued_at?->format('Y-m-d') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted small">{{ __('None yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @break
            @case('supplier_orders')
                <h2 class="h6 mb-3">{{ __('Supplier orders') }}</h2>
                @php $link = RouteFacade::has('supplier-orders.show'); @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Status') }}</th><th>{{ __('Currency') }}</th><th>{{ __('Delivery date') }}</th></tr></thead>
                        <tbody>
                        @forelse($rows as $order)
                            <tr>
                                <td class="app-num">@if($link)<a href="{{ route('supplier-orders.show', $order) }}">{{ $order->number }}</a>@else{{ $order->number }}@endif</td>
                                <td><span class="app-pill">{{ __(ucfirst($order->status)) }}</span></td>
                                <td>{{ $order->currency }}</td>
                                <td class="app-num">{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted small">{{ __('None yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @break
            @case('shipments')
                <h2 class="h6 mb-3">{{ __('Shipments') }}</h2>
                @php $link = RouteFacade::has('containers.show'); $shipmentLink = RouteFacade::has('shipments.show'); @endphp
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Booking no.') }}</th><th>{{ __('ETD') }}</th><th>{{ __('ETA') }}</th><th>{{ __('Containers') }}</th></tr></thead>
                        <tbody>
                        @forelse($rows as $shipment)
                            <tr>
                                <td class="app-num">@if($shipmentLink)<a href="{{ route('shipments.show', $shipment) }}">{{ $shipment->number }}</a>@else{{ $shipment->number }}@endif</td>
                                <td class="app-num">{{ $shipment->booking_no ?: '—' }}</td>
                                <td class="app-num">{{ $shipment->etd?->format('Y-m-d') ?? '—' }}</td>
                                <td class="app-num">{{ $shipment->eta?->format('Y-m-d') ?? '—' }}</td>
                                <td>
                                    @forelse($shipment->containers as $container)
                                        @if($link)<a href="{{ route('containers.show', $container) }}" class="app-num me-1">{{ $container->container_no ?: '#'.$container->id }}</a>@else<span class="app-num me-1">{{ $container->container_no ?: '#'.$container->id }}</span>@endif
                                    @empty — @endforelse
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted small">{{ __('None yet.') }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @break
        @endswitch
    </div>
@endforeach
