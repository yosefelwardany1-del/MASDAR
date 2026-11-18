{{-- A printed credit note (to a client, English) or debit note (to a factory, English and
     Chinese), in MASDAR's letterhead. Standalone A4, inline CSS, no Vite. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    $credit = $note->isCredit();
    $money = fn ($v) => PiAmounts::money((string) $v, $note->currency);
    $plain = fn ($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';
    $watermark = ['draft' => 'DRAFT', 'void' => 'VOID'][$note->status] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $credit ? 'Credit Note' : 'Debit Note' }} {{ $note->isDraft() ? 'DRAFT' : $note->number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, Helvetica, "Noto Sans CJK SC", "Microsoft YaHei", sans-serif; font-size: 10pt; direction: ltr; text-align: left; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .logo { text-align: center; }
        .logo img { max-height: 30mm; max-width: 90mm; }
        .company { text-align: center; font-size: 20pt; font-weight: bold; margin: 3mm 0 1.5mm; }
        .address, .tel { text-align: center; font-size: 10pt; line-height: 1.4; }
        .title { text-align: center; font-size: 16pt; font-weight: bold; margin: 6mm 0 5mm; }
        .title small { display: block; font-size: 11pt; font-weight: normal; }
        .parties { display: flex; justify-content: space-between; gap: 10mm; margin-bottom: 5mm; }
        .parties .row { margin-bottom: 1.5mm; }
        .parties b { display: inline-block; min-width: 30mm; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 9pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.8mm 1.5mm; vertical-align: top; }
        table.lines th { background: #f2f2f2; text-align: center; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines td.desc { white-space: pre-line; }
        table.lines tfoot td { font-weight: bold; }
        .reason { margin: 4mm 0; white-space: pre-line; }
        .sign { display: flex; justify-content: space-between; margin-top: 18mm; }
        .sign div { width: 70mm; border-top: 1px solid #000; padding-top: 2mm; text-align: center; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-30deg); pointer-events: none; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('trade-notes.show', $note) }}">Back</a>
</div>
<div class="sheet">
    @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif
    <div class="logo"><img src="{{ $logoUrl }}" alt="{{ $companyName }}"></div>
    <div class="company">{{ $companyName }}</div>
    <div class="address">{{ $address }}</div>
    <div class="tel">{{ $phone }}</div>

    <div class="title">{{ $credit ? 'CREDIT NOTE' : 'DEBIT NOTE' }}@unless($credit)<small>扣款通知单</small>@endunless</div>

    <div class="parties">
        <div>
            <div class="row"><b>{{ $credit ? 'To:' : 'To / 致:' }}</b> {{ $note->party?->name }}@if(! $credit && $note->party?->name_local) {{ $note->party->name_local }}@endif</div>
            @if($credit && $note->proformaInvoice)<div class="row"><b>PI:</b> {{ $note->proformaInvoice->reference() }}</div>@endif
            @if(! $credit && $note->supplierOrder)<div class="row"><b>Order / 订单:</b> {{ $note->supplierOrder->number }}</div>@endif
            @if($note->claim)<div class="row"><b>{{ $credit ? 'Claim:' : 'Claim / 索赔:' }}</b> {{ $note->claim->number }}</div>@endif
        </div>
        <div>
            <div class="row"><b>{{ $credit ? 'No:' : 'No / 编号:' }}</b> {{ $note->isDraft() ? 'DRAFT' : $note->number }}</div>
            <div class="row"><b>{{ $credit ? 'Date:' : 'Date / 日期:' }}</b> {{ $note->issued_on?->format('Y-m-d') ?? now()->format('Y-m-d') }}</div>
            <div class="row"><b>{{ $credit ? 'Currency:' : 'Currency / 币种:' }}</b> {{ $note->currency }}</div>
        </div>
    </div>

    <div class="reason">{{ $note->reason }}</div>

    <table class="lines">
        <thead><tr>
            <th style="width: 10mm">{{ $credit ? 'No' : 'No 序号' }}</th>
            <th>{{ $credit ? 'DESCRIPTION' : 'DESCRIPTION 说明' }}</th>
            <th style="width: 22mm">{{ $credit ? 'QTY' : 'QTY 数量' }}</th>
            <th style="width: 28mm">{{ $credit ? 'UNIT PRICE' : 'UNIT PRICE 单价' }}</th>
            <th style="width: 32mm">{{ $credit ? 'AMOUNT' : 'AMOUNT 金额' }}</th>
        </tr></thead>
        <tbody>
        @foreach($note->lines as $line)
            <tr>
                <td class="num">{{ $line->line_no }}</td>
                <td class="desc">{{ $line->description }}</td>
                <td class="num">{{ $plain($line->quantity) }}</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $money($line->amount) }}</td>
            </tr>
        @endforeach
        </tbody>
        <tfoot><tr><td colspan="4" style="text-align: right">{{ $credit ? 'TOTAL CREDITED' : 'TOTAL DEDUCTED 扣款合计' }}</td><td class="num">{{ $money($total) }}</td></tr></tfoot>
    </table>

    <p>{{ $credit
        ? 'This amount is credited to your account with '.$companyName.' and deducted from what is due.'
        : 'This amount is deducted from what '.$companyName.' owes you on the order above. 上述金额将从本公司应付贵司的货款中扣除。' }}</p>

    <div class="sign">
        <div>{{ $companyName }}</div>
        <div>{{ $credit ? 'Received by' : 'Acknowledged by the supplier 供应商确认（签字盖章）' }}</div>
    </div>
</div>
</body>
</html>
