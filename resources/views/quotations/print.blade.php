{{-- The quotation as sent to the client (spec §5.2): English, in MASDAR's layout like the PI;
     standalone and self-contained. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    $money = fn ($amount) => PiAmounts::money((string) $amount, $quotation->currency);
    $photos = Route::has('products.photo');
    $hasQty = $quotation->lines->contains(fn ($l) => $l->cartons !== null);
    $watermark = ['draft' => 'DRAFT', 'declined' => 'DECLINED'][$quotation->status] ?? null;
    $plain = fn ($v) => $v === null ? '' : PiAmounts::number((string) $v);
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quotation {{ $quotation->isDraft() ? 'DRAFT' : $quotation->number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .logo { text-align: center; }
        .logo img { max-height: 26mm; max-width: 90mm; }
        .company { text-align: center; font-size: 18pt; font-weight: bold; margin: 3mm 0 1.5mm; }
        .address, .tel { text-align: center; font-size: 10pt; line-height: 1.4; }
        .title { text-align: center; font-size: 16pt; font-weight: bold; margin: 6mm 0 5mm; letter-spacing: 1px; }
        .parties { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 4mm; }
        .parties .row { margin-bottom: 1.5mm; }
        .parties b { display: inline-block; min-width: 26mm; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.5mm 1.2mm; text-align: center; vertical-align: middle; }
        table.lines th { background: #f2f2f2; font-weight: bold; }
        table.lines td.desc { text-align: left; white-space: pre-line; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines td.pic { width: 22mm; padding: 1mm; }
        table.lines td.pic img { display: block; margin: 0 auto; max-width: 20mm; max-height: 20mm; }
        tr { page-break-inside: avoid; }
        .terms { margin-top: 6mm; white-space: pre-line; }
        .valid { margin-top: 4mm; font-weight: bold; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-30deg); pointer-events: none; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('quotations.show', $quotation) }}">Back</a>
</div>
<div class="sheet">
    @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif
    <div class="logo"><img src="{{ $logoUrl }}" alt="{{ $companyName }}"></div>
    <div class="company">{{ $companyName }}</div>
    <div class="address">{{ $address }}</div>
    <div class="tel">{{ $phone }}</div>

    <div class="title">QUOTATION</div>

    <div class="parties">
        <div>
            <div class="row"><b>To:</b> {{ $quotation->client?->name }}</div>
            <div class="row"><b>Terms of sale:</b> {{ $quotation->incoterm }}</div>
            <div class="row"><b>Currency:</b> {{ $quotation->currency }}</div>
        </div>
        <div>
            <div class="row"><b>No:</b> {{ $quotation->isDraft() ? 'DRAFT' : $quotation->number }}</div>
            <div class="row"><b>Date:</b> {{ $quotation->quoted_on->format('Y-m-d') }}</div>
            <div class="row"><b>Valid until:</b> {{ $quotation->valid_until->format('Y-m-d') }}</div>
        </div>
    </div>

    <table class="lines">
        <thead><tr>
            <th>No</th><th>PICTURE</th><th>ITEM NAME &amp; DESCRIPTION</th><th>UNIT</th><th>QTY/CTN</th><th>MOQ</th>
            <th>CBM/CTN</th><th>KG/CTN</th><th>PRICE</th>@if($hasQty)<th>CTN</th><th>AMOUNT</th>@endif
        </tr></thead>
        <tbody>
        @foreach($quotation->lines as $line)
            <tr>
                <td>{{ $line->line_no }}</td>
                <td class="pic">@if($photos && $line->product?->photo)<img src="{{ route('products.photo', $line->product_id) }}" alt="" onerror="this.style.visibility='hidden'">@endif</td>
                <td class="desc">{{ $line->description }}</td>
                <td>{{ $unitNames[$line->unit] ?? $line->unit }}</td>
                <td class="num">{{ $plain($line->qty_per_carton) }}</td>
                <td class="num">{{ $plain($line->moq) }}</td>
                <td class="num">{{ $plain($line->cbm_per_carton) }}</td>
                <td class="num">{{ $plain($line->kg_per_carton) }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                @if($hasQty)<td class="num">{{ $plain($line->cartons) }}</td><td class="num">{{ $line->amount() ? $money($line->amount()) : '' }}</td>@endif
            </tr>
        @endforeach
        </tbody>
    </table>

    @if($quotation->terms)<div class="terms">{{ $quotation->terms }}</div>@endif
    <div class="valid">Prices valid until {{ $quotation->valid_until->format('j F Y') }}.</div>
</div>
</body>
</html>
