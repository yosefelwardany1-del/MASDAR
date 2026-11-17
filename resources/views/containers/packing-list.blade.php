{{-- The packing list of a container, from its loads (spec §5.5). A standalone printable page:
     always English and left-to-right, like MASDAR's other printed documents. --}}
@php
    $num = fn ($v) => \App\Services\Shipping\Num::show($v);
    $containerNo = $container->container_no ?: '#'.$container->id;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Packing List {{ $containerNo }} · {{ $company->company_name }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #111; margin: 24px; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .head img { max-height: 64px; }
        .company { font-size: 18px; font-weight: bold; }
        .muted { color: #555; }
        h1 { text-align: center; font-size: 20px; letter-spacing: 2px; margin: 16px 0; }
        .info { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px 16px; margin-bottom: 16px; }
        .info div span { display: block; color: #555; font-size: 10px; text-transform: uppercase; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 5px 6px; vertical-align: top; }
        th { background: #eee; text-align: left; font-size: 11px; }
        td.n, th.n { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: bold; background: #f6f6f6; }
        .actions { margin-bottom: 16px; }
        .actions button { padding: 6px 14px; font-size: 13px; cursor: pointer; }
        @media print { body { margin: 0; } .actions { display: none; } }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Print</button></div>

<div class="head">
    <div>
        <div class="company">{{ $company->company_name }}</div>
        @if($business->company_address)<div class="muted">{{ $business->company_address }}</div>@endif
        @if($business->company_phone)<div class="muted">{{ $business->company_phone }}</div>@endif
    </div>
    <img src="{{ route('settings.logo') }}" alt="" onerror="this.style.display='none'">
</div>

<h1>PACKING LIST</h1>

<div class="info">
    <div><span>Container no.</span>{{ $containerNo }}</div>
    <div><span>Container type</span>{{ $type ?: '—' }}</div>
    <div><span>Seal no.</span>{{ $container->seal_no ?: '—' }}</div>
    <div><span>Shipment</span>{{ $shipment->number }}</div>
    <div><span>B/L no.</span>{{ $shipment->bl_number ?: '—' }}</div>
    <div><span>Vessel / voyage</span>{{ $shipment->vessel ?: '—' }}{{ $shipment->voyage ? ' / '.$shipment->voyage : '' }}</div>
    <div><span>Port of loading</span>{{ $pol ?: '—' }}</div>
    <div><span>Port of discharge</span>{{ $pod ?: '—' }}</div>
    <div><span>ETD</span>{{ $shipment->etd?->format('d M Y') ?? '—' }}</div>
    <div><span>ETA</span>{{ $shipment->eta?->format('d M Y') ?? '—' }}</div>
    <div><span>Shipping company</span>{{ $shippingCompany ?: '—' }}</div>
    <div><span>Date</span>{{ now()->format('d M Y') }}</div>
</div>

<table>
    <thead>
    <tr>
        <th class="n">No.</th><th>Client</th><th>PI No.</th><th>Item</th>
        <th class="n">CTN</th><th class="n">QTY</th><th>Unit</th><th class="n">CBM</th><th class="n">KG</th>
    </tr>
    </thead>
    <tbody>
    @forelse($lines as $i => $line)
        <tr>
            <td class="n">{{ $i + 1 }}</td>
            <td>{{ $line->client_name }}</td>
            <td>{{ $line->pi_number }}</td>
            <td>{{ $line->product_name }}@if($line->description)<br><span class="muted">{{ $line->description }}</span>@endif</td>
            <td class="n">{{ $num($line->cartons) }}</td>
            <td class="n">{{ $num($line->qty) }}</td>
            <td>{{ strtoupper((string) $line->unit) }}</td>
            <td class="n">{{ $num($line->cbm) }}</td>
            <td class="n">{{ $num($line->kg) }}</td>
        </tr>
    @empty
        <tr><td colspan="9" style="text-align:center; padding:20px" class="muted">Nothing is loaded in this container.</td></tr>
    @endforelse
    </tbody>
    <tfoot>
    <tr>
        <td colspan="4">TOTAL</td>
        <td class="n">{{ $num($totals->cartons) }}</td>
        <td class="n">{{ $num($totals->qty) }}</td>
        <td></td>
        <td class="n">{{ $num($totals->cbm) }}</td>
        <td class="n">{{ $num($totals->kg) }}</td>
    </tr>
    </tfoot>
</table>
</body>
</html>
