{{-- The printed Proforma Invoice in MASDAR's own layout (spec §5.2, source S1). A standalone A4
     page, always in English and left to right: the document's language is English, whatever
     the reader's screen language. Self-contained: inline CSS, no Vite. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    use Illuminate\Support\Facades\Route;

    $money = fn (?string $amount) => PiAmounts::money($amount, $pi->currency);
    $photos = Route::has('products.photo');
    $watermark = ['draft' => 'DRAFT', 'cancelled' => 'CANCELLED', 'superseded' => 'SUPERSEDED'][$pi->status] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proforma Invoice {{ $pi->reference() }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; direction: ltr; text-align: left; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .logo { text-align: center; }
        .logo img { max-height: 30mm; max-width: 90mm; }
        .company { text-align: center; font-size: 20pt; font-weight: bold; margin: 3mm 0 1.5mm; letter-spacing: .3px; }
        .address, .tel { text-align: center; font-size: 10pt; line-height: 1.4; }
        .title { text-align: center; font-size: 16pt; font-weight: bold; margin: 6mm 0 5mm; }
        .parties { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 4mm; font-size: 10pt; }
        .parties .row { margin-bottom: 1.5mm; }
        .parties b { display: inline-block; min-width: 24mm; }
        .parties .right b { min-width: 20mm; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.5mm 1.2mm; text-align: center; vertical-align: middle; }
        table.lines th { background: #f2f2f2; font-weight: bold; }
        table.lines td.desc { text-align: left; white-space: pre-line; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines td.pic { width: 24mm; padding: 1mm; }
        table.lines td.pic img { display: block; margin: 0 auto; max-width: 22mm; max-height: 22mm; }
        table.lines tr.total td { font-weight: bold; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .terms { text-align: center; font-weight: bold; font-size: 11pt; margin-top: 7mm; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-30deg); pointer-events: none; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
            table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ ($portal ?? false) ? route('portal.home') : route('proforma-invoices.show', $pi) }}">Back</a>
</div>

<div class="sheet">
    @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif

    <div class="logo"><img src="{{ $logoUrl }}" alt="{{ $companyName }}"></div>
    <div class="company">{{ $companyName }}</div>
    <div class="address">{{ $address }}</div>
    <div class="tel">{{ $phone }}</div>

    <div class="title">Proforma Invoice (PI)</div>

    <div class="parties">
        <div class="left">
            <div class="row"><b>To:</b> {{ $pi->client?->name }}</div>
            <div class="row"><b>Shipped to:</b> {{ $pi->shipped_to }}</div>
        </div>
        <div class="right">
            <div class="row"><b>PO NO:</b> {{ $pi->po_number }}</div>
            <div class="row"><b>PO DATE:</b> {{ $pi->po_date?->format('Y-m-d') }}</div>
        </div>
    </div>

    <table class="lines">
        <thead>
        <tr>
            <th>No</th><th>PICTURE</th><th>ITEM NAME &amp; DESCRIPTION</th><th>CTN</th><th>QTY/CTN</th><th>TOTAL QTY</th>
            <th>UNIT</th><th>PRICE</th><th>AMOUNT</th><th>Total CBM</th><th>Total KG</th>
        </tr>
        </thead>
        <tbody>
        @foreach($pi->lines as $line)
            <tr>
                <td>{{ $line->line_no }}</td>
                <td class="pic">
                    @if($photos && $line->product?->photo)
                        <img src="{{ route('products.photo', $line->product_id) }}" alt="" onerror="this.style.visibility='hidden'">
                    @endif
                </td>
                <td class="desc">{{ $line->itemText() }}</td>
                <td class="num">{{ PiAmounts::number($line->cartons) }}</td>
                <td class="num">{{ PiAmounts::number($line->qty_per_carton) }}</td>
                <td class="num">{{ PiAmounts::number($line->total_qty) }}</td>
                <td>{{ $unitNames[$line->unit] ?? $line->unit }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $money($line->amount) }}</td>
                <td class="num">{{ PiAmounts::number($line->total_cbm) }}</td>
                <td class="num">{{ PiAmounts::number($line->total_kg) }}</td>
            </tr>
        @endforeach
        {{-- B30: cartons always add up; quantities only when every line is in the same unit. --}}
        <tr class="total">
            <td colspan="3">Total</td>
            <td class="num">{{ PiAmounts::number($totals->cartons) }}</td>
            <td></td>
            <td class="num">{{ $totals->total_qty !== null ? PiAmounts::number($totals->total_qty) : '' }}</td>
            <td>{{ $totals->unit !== null ? ($unitNames[$totals->unit] ?? $totals->unit) : '' }}</td>
            <td></td>
            <td class="num">{{ $money($totals->amount) }}</td>
            <td class="num">{{ PiAmounts::number($totals->total_cbm) }}</td>
            <td class="num">{{ PiAmounts::number($totals->total_kg) }}</td>
        </tr>
        </tbody>
    </table>

    <div class="terms">{{ $pi->terms_text }}</div>
</div>
</body>
</html>
