{{-- The purchase contract (采购合同) for the factory: English with Chinese, MASDAR as the buyer,
     the factory's own prices. It never shows a client's name or MASDAR's selling price.
     Standalone and self-contained, like the printed PI. --}}
@php
    use App\Services\ProformaInvoices\PiAmounts;
    $money = fn ($amount) => PiAmounts::money((string) $amount, $order->currency);
    $n = fn ($v) => PiAmounts::number((string) $v);
    $photos = Route::has('products.photo');
    $totalCartons = $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->cartons, 4), '0');
    $totalQty = $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->total_qty, 4), '0');
    $totalAmount = $lines->reduce(fn ($c, $l) => bcadd($c, (string) $l->amount, 4), '0');
    $totalCbm = $lines->reduce(fn ($c, $l) => bcadd($c, bcmul((string) $l->cartons, (string) ($l->piLine?->cbm_per_carton ?? 0), 4), 4), '0');
    $totalKg = $lines->reduce(fn ($c, $l) => bcadd($c, bcmul((string) $l->cartons, (string) ($l->piLine?->kg_per_carton ?? 0), 4), 4), '0');
    $deposit = $order->deposit_percent !== null ? rtrim(rtrim((string) $order->deposit_percent, '0'), '.') : null;
    $supplier = $order->supplier;
    $watermark = $order->status === 'cancelled' ? 'CANCELLED' : null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase contract {{ $order->number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #000; font-family: Arial, "Microsoft YaHei", "PingFang SC", "Noto Sans CJK SC", sans-serif; font-size: 9.5pt; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 10mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #1f3a5f; padding-bottom: 3mm; }
        .head img { max-height: 16mm; max-width: 55mm; }
        .head .co { text-align: right; font-size: 8.5pt; line-height: 1.4; }
        .head .co b { display: block; font-size: 11pt; color: #1f3a5f; }
        h1 { text-align: center; font-size: 16pt; margin: 5mm 0 1mm; letter-spacing: 1px; }
        .zh { color: #444; }
        .sub { text-align: center; margin-bottom: 5mm; }
        .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 6mm; margin-bottom: 4mm; }
        .party { border: 1px solid #999; padding: 3mm; line-height: 1.5; }
        .party h2 { margin: 0 0 1.5mm; font-size: 10pt; color: #1f3a5f; }
        .meta { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2mm 6mm; margin-bottom: 4mm; }
        .meta span { color: #444; }
        table.lines { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
        table.lines th, table.lines td { border: 1px solid #000; padding: 1.3mm 1mm; text-align: center; vertical-align: middle; }
        table.lines th { background: #f2f2f2; font-weight: bold; line-height: 1.25; }
        table.lines td.desc { text-align: left; white-space: pre-line; }
        table.lines td.num { text-align: right; white-space: nowrap; }
        table.lines td.pic { width: 20mm; padding: .8mm; }
        table.lines td.pic img { display: block; margin: 0 auto; max-width: 18mm; max-height: 18mm; }
        table.lines tr.total td { font-weight: bold; }
        tr { page-break-inside: avoid; }
        .terms { margin-top: 5mm; line-height: 1.55; }
        .terms__text { white-space: pre-line; }
        .terms h2, .pay h2 { font-size: 10pt; margin: 0 0 1.5mm; color: #1f3a5f; }
        .pay { margin-top: 4mm; line-height: 1.5; }
        .sign { display: grid; grid-template-columns: 1fr 1fr; gap: 14mm; margin-top: 12mm; }
        .sign div { border-top: 1px solid #000; padding-top: 2mm; min-height: 26mm; }
        .watermark { position: absolute; top: 45%; left: 0; right: 0; text-align: center; font-size: 80pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-30deg); pointer-events: none; }
        @media print { body { background: #fff; } .no-print { display: none !important; } .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; } table.lines th { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('supplier-orders.show', $order) }}">Back</a>
</div>
<div class="sheet">
    @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif
    <div class="head">
        <img src="{{ $logoUrl }}" alt="{{ $companyName }}">
        <div class="co"><b>{{ $companyName }}</b>{{ $address }}<br>{{ $phone }}</div>
    </div>

    <h1>PURCHASE CONTRACT <span class="zh">采购合同</span></h1>
    <div class="sub">No. 合同编号: <b>{{ $order->number }}</b> &nbsp;·&nbsp; Date 日期: <b>{{ ($order->confirmation_date ?? $order->created_at)?->format('Y-m-d') }}</b></div>

    <div class="parties">
        <div class="party">
            <h2>Buyer 买方</h2>
            <b>{{ $companyName }}</b><br>
            {{ $address }}<br>
            {{ $phone }}
        </div>
        <div class="party">
            <h2>Seller 卖方</h2>
            <b>{{ $supplier?->name }}</b>@if($supplier?->name_local)<br><b>{{ $supplier->name_local }}</b>@endif
            @if($supplier?->factory_address)<br>{{ $supplier->factory_address }}@endif
            @if($contact)<br>{{ $contact->name }}@if($contact->phone) · {{ $contact->phone }}@endif @if($contact->wechat) · WeChat {{ $contact->wechat }}@endif @endif
        </div>
    </div>

    <div class="meta">
        <div><span>Currency 币种:</span> <b>{{ $order->currency }}</b></div>
        <div><span>Delivery date 交货日期:</span> <b>{{ $order->delivery_date?->format('Y-m-d') ?? '—' }}</b></div>
        <div><span>Deposit 定金:</span> <b>{{ $deposit !== null ? $deposit.'%' : '—' }}</b></div>
    </div>

    <table class="lines">
        <thead><tr>
            <th>No.<br>序号</th><th>Picture<br>图片</th><th>Item &amp; description<br>品名及描述</th><th>Cartons<br>箱数</th><th>Qty/ctn<br>每箱数量</th>
            <th>Total qty<br>总数量</th><th>Unit<br>单位</th><th>Unit price<br>单价</th><th>Amount<br>金额</th><th>CBM<br>体积</th><th>KG<br>重量</th>
        </tr></thead>
        <tbody>
        @foreach($lines as $i => $line)
            @php $pl = $line->piLine; $product = $pl?->product; $unit = $units->get($pl?->unit); @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td class="pic">@if($photos && $product?->photo)<img src="{{ route('products.photo', $product->id) }}" alt="" onerror="this.style.visibility='hidden'">@endif</td>
                <td class="desc">@if($product?->name_local)<b>{{ $product->name_local }}</b><br>@endif{{ $pl?->description }}@if($product?->code)<br><small>{{ $product->code }}</small>@endif</td>
                <td class="num">{{ $n($line->cartons) }}</td>
                <td class="num">{{ $n($line->qty_per_carton) }}</td>
                <td class="num">{{ $n($line->total_qty) }}</td>
                <td>{{ $unit?->name ?? $pl?->unit }}@if($unit?->name_zh)<br>{{ $unit->name_zh }}@endif</td>
                <td class="num">{{ $money($line->unit_price) }}</td>
                <td class="num">{{ $money($line->amount) }}</td>
                <td class="num">{{ $n(bcmul((string) $line->cartons, (string) ($pl?->cbm_per_carton ?? 0), 4)) }}</td>
                <td class="num">{{ $n(bcmul((string) $line->cartons, (string) ($pl?->kg_per_carton ?? 0), 4)) }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td colspan="3">Total 合计</td>
            <td class="num">{{ $n($totalCartons) }}</td>
            <td></td>
            <td class="num">{{ $n($totalQty) }}</td>
            <td></td><td></td>
            <td class="num">{{ $money($totalAmount) }}</td>
            <td class="num">{{ $n($totalCbm) }}</td>
            <td class="num">{{ $n($totalKg) }}</td>
        </tr>
        </tbody>
    </table>

    <div class="pay">
        <h2>Payment 付款方式</h2>
        @if($order->payment_terms){{ $order->payment_terms }}@elseif($deposit !== null){{ $deposit }}% deposit when this contract is signed; the balance before loading. 签订合同时支付 {{ $deposit }}% 定金，装柜前付清尾款。@else As agreed. 按双方约定。@endif
    </div>

    <div class="terms">
        <h2>Terms 条款</h2>
        <div class="terms__text">{{ trim($terms) }}</div>
    </div>

    <div class="sign">
        <div>Buyer 买方 (signature and stamp 签字盖章)<br><b>{{ $companyName }}</b></div>
        <div>Seller 卖方 (signature and stamp 签字盖章)<br><b>{{ $supplier?->name_local ?: $supplier?->name }}</b></div>
    </div>
</div>
</body>
</html>
