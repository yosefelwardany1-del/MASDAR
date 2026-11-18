{{-- A printed service invoice (commission, inspection, freight recharged …), English, MASDAR's
     letterhead. Standalone A4, inline CSS, no Vite. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    $money = fn ($v) => PiAmounts::money((string) $v, $invoice->currency);
    $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $watermark = ['draft' => 'DRAFT', 'void' => 'VOID'][$invoice->status] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice {{ $invoice->isDraft() ? 'DRAFT' : $invoice->number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .logo { text-align: center; } .logo img { max-height: 30mm; max-width: 90mm; }
        .company { text-align: center; font-size: 20pt; font-weight: bold; margin: 3mm 0 1.5mm; }
        .address, .tel { text-align: center; line-height: 1.4; }
        .title { text-align: center; font-size: 16pt; font-weight: bold; margin: 6mm 0 5mm; }
        .parties { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 5mm; }
        .parties .row { margin-bottom: 1.5mm; } .parties b { display: inline-block; min-width: 24mm; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 9pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.8mm 1.5mm; vertical-align: top; }
        table.lines th { background: #f2f2f2; text-align: center; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines tfoot td { font-weight: bold; }
        .notes, .bank { margin-top: 5mm; white-space: pre-line; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-30deg); }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="toolbar no-print"><button type="button" onclick="window.print()">Print</button><a href="{{ route('service-invoices.show', $invoice) }}">Back</a></div>
<div class="sheet">
    @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif
    <div class="logo"><img src="{{ $logoUrl }}" alt="{{ $companyName }}"></div>
    <div class="company">{{ $companyName }}</div>
    <div class="address">{{ $address }}</div>
    <div class="tel">{{ $phone }}</div>
    <div class="title">INVOICE</div>
    <div class="parties">
        <div>
            <div class="row"><b>To:</b> {{ $invoice->client?->name }}</div>
            @if($invoice->client?->address)<div class="row" style="white-space: pre-line">{{ $invoice->client->address }}</div>@endif
            @if($invoice->proformaInvoice)<div class="row"><b>PI:</b> {{ $invoice->proformaInvoice->reference() }}</div>@endif
        </div>
        <div>
            <div class="row"><b>No:</b> {{ $invoice->isDraft() ? 'DRAFT' : $invoice->number }}</div>
            <div class="row"><b>Date:</b> {{ $invoice->issued_on?->format('Y-m-d') ?? now()->format('Y-m-d') }}</div>
            @if($invoice->due_on)<div class="row"><b>Due:</b> {{ $invoice->due_on->format('Y-m-d') }}</div>@endif
        </div>
    </div>
    <table class="lines">
        <thead><tr><th style="width: 10mm">No</th><th>DESCRIPTION</th><th style="width: 18mm">QTY</th><th style="width: 28mm">UNIT PRICE</th><th style="width: 32mm">AMOUNT</th></tr></thead>
        <tbody>
        @foreach($invoice->lines as $l)
            <tr><td class="num">{{ $l->line_no }}</td><td><b>{{ $categories[$l->category] ?? $l->category }}</b><br>{{ $l->description }}</td><td class="num">{{ $plain($l->quantity) }}</td><td class="num">{{ $money($l->unit_price) }}</td><td class="num">{{ $money($l->amount) }}</td></tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="4" style="text-align: right">TOTAL</td><td class="num">{{ $money($total) }}</td></tr></tfoot>
    </table>
    @if($invoice->notes)<div class="notes">{{ $invoice->notes }}</div>@endif
    @if($bank)<div class="bank"><b>Bank details:</b><br>{{ $bank }}</div>@endif
</div>
</body>
</html>
