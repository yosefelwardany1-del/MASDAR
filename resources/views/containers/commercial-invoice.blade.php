{{-- The commercial invoice for one client's goods in a container (spec §5.5): English, for
     customs at both ends; MASDAR sells, the client buys. Standalone and self-contained. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    $n = fn ($v) => PiAmounts::number((string) $v);
    $byCurrency = $lines->groupBy('currency');
    $money = fn ($amount, $currency) => PiAmounts::money((string) $amount, $currency);
    $sum = fn ($rows, $f) => $rows->reduce(fn ($c, $r) => bcadd($c, (string) $r->{$f}, 4), '0');
    $pis = $lines->map(fn ($l) => $l->pi_number.($l->revision > 1 ? '-R'.$l->revision : ''))->unique()->join(', ');
    $pos = $lines->pluck('po_number')->filter()->unique()->join(', ');
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commercial invoice {{ $number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 9.5pt; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #1f3a5f; padding-bottom: 3mm; }
        .head img { max-height: 16mm; max-width: 55mm; }
        .head .co { text-align: right; font-size: 8.5pt; line-height: 1.4; }
        .head .co b { display: block; font-size: 11pt; color: #1f3a5f; }
        h1 { text-align: center; font-size: 16pt; margin: 5mm 0 4mm; letter-spacing: 1px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; border: 1px solid #000; margin-bottom: 4mm; }
        .grid > div { padding: 2.5mm 3mm; border-bottom: 1px solid #000; line-height: 1.5; }
        .grid > div:nth-child(odd) { border-right: 1px solid #000; }
        .grid > div:nth-last-child(-n+2) { border-bottom: 0; }
        .grid h2 { margin: 0 0 1mm; font-size: 8pt; text-transform: uppercase; color: #444; letter-spacing: .3px; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.4mm 1.2mm; text-align: center; vertical-align: middle; }
        table.lines th { background: #f2f2f2; font-weight: bold; }
        table.lines td.desc { text-align: left; white-space: pre-line; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines tr.total td { font-weight: bold; }
        tr { page-break-inside: avoid; }
        .after { margin-top: 5mm; display: grid; grid-template-columns: 1fr 1fr; gap: 8mm; line-height: 1.5; }
        .after h2 { font-size: 9pt; margin: 0 0 1mm; color: #1f3a5f; }
        .bank { white-space: pre-line; }
        .sign { margin-top: 14mm; text-align: right; }
        .sign div { display: inline-block; min-width: 70mm; border-top: 1px solid #000; padding-top: 2mm; text-align: center; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('containers.show', $container) }}">Back</a>
</div>
<div class="sheet">
    <div class="head">
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}">
        <div class="co"><b>{{ $companyName }}</b>{{ $address }}<br>{{ $phone }}</div>
    </div>
    <h1>COMMERCIAL INVOICE</h1>

    <div class="grid">
        <div><h2>Seller / Exporter</h2><b>{{ $companyName }}</b><br>{{ $address }}<br>{{ $phone }}</div>
        <div><h2>Invoice</h2>No. <b>{{ $number }}</b><br>Date: <b>{{ $date }}</b><br>Terms of sale: <b>{{ $incoterm }}</b></div>
        <div><h2>Buyer / Consignee</h2><b>{{ $client->name }}</b>@if($client->address)<br>{{ $client->address }}@endif<br>{{ trim(($client->city ? $client->city.', ' : '').$country, ', ') }}</div>
        <div><h2>References</h2>PI: {{ $pis }}@if($pos)<br>Buyer's PO: {{ $pos }}@endif</div>
        <div><h2>Shipment</h2>Container: <b>{{ $container->container_no }}</b>@if($container->seal_no) · Seal {{ $container->seal_no }}@endif<br>@if($shipment->vessel)Vessel / voyage: {{ $shipment->vessel }} {{ $shipment->voyage }}<br>@endif @if($shipment->bl_number)B/L: {{ $shipment->bl_number }}@endif</div>
        <div><h2>Route</h2>Port of loading: <b>{{ $pol ?: '—' }}</b><br>Port of discharge: <b>{{ $pod ?: '—' }}</b><br>Country of origin: <b>China</b></div>
    </div>

    <table class="lines">
        <thead><tr>
            <th>No</th><th>DESCRIPTION OF GOODS</th><th>HS CODE</th><th>CTN</th><th>QTY</th><th>UNIT</th><th>UNIT PRICE</th><th>AMOUNT</th><th>CBM</th><th>G.W. (KG)</th>
        </tr></thead>
        <tbody>
        @foreach($lines as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="desc">{{ $line->description }}</td>
                <td>{{ $line->hs_code }}</td>
                <td class="num">{{ $n($line->cartons) }}</td>
                <td class="num">{{ $n($line->qty) }}</td>
                <td>{{ $units[$line->unit] ?? $line->unit }}</td>
                <td class="num">{{ $money($line->unit_price, $line->currency) }}</td>
                <td class="num">{{ $money($line->amount, $line->currency) }}</td>
                <td class="num">{{ $n($line->cbm) }}</td>
                <td class="num">{{ $n($line->kg) }}</td>
            </tr>
        @endforeach
        @foreach($byCurrency as $currency => $rows)
            <tr class="total">
                <td colspan="3">Total{{ $byCurrency->count() > 1 ? ' ('.$currency.')' : '' }}</td>
                <td class="num">{{ $n($sum($rows, 'cartons')) }}</td>
                <td class="num">{{ $n($sum($rows, 'qty')) }}</td>
                <td></td><td></td>
                <td class="num">{{ $money($sum($rows, 'amount'), $currency) }}</td>
                <td class="num">{{ $n($sum($rows, 'cbm')) }}</td>
                <td class="num">{{ $n($sum($rows, 'kg')) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="after">
        <div>
            <h2>Packing</h2>
            {{ $n($sum($lines, 'cartons')) }} cartons · {{ $n($sum($lines, 'cbm')) }} CBM · {{ $n($sum($lines, 'kg')) }} KG gross.
        </div>
        @if($bank)
            <div><h2>Bank details</h2><div class="bank">{{ $bank }}</div></div>
        @endif
    </div>
    <div class="sign"><div>For and on behalf of<br><b>{{ $companyName }}</b></div></div>
</div>
</body>
</html>
