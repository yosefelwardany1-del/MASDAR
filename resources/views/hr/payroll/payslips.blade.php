{{-- Payslips (spec §5.7): one A4 page per employee, in English with Chinese labels, as MASDAR's
     staff are mostly Chinese-speaking. Standalone and self-contained, like the printed PI. --}}
@php
    use App\Models\Lookup;
    use App\Services\Accounting\Decimal;
    $f = fn ($v) => Decimal::format($v);
    $month = $run->period->locale('en')->isoFormat('MMMM YYYY');
    $monthZh = $run->period->format('Y').'年'.$run->period->format('n').'月';
    $watermark = ['draft' => 'DRAFT', 'void' => 'VOID'][$run->status] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslips {{ $run->isDraft() ? 'DRAFT' : $run->number }} · {{ $month }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body { background: #e9ecef; color: #111; font-family: Arial, "Microsoft YaHei", "PingFang SC", "Noto Sans CJK SC", sans-serif; font-size: 10pt; }
        .toolbar { position: sticky; top: 0; display: flex; gap: 8px; justify-content: center; padding: 10px; background: #fff; border-bottom: 1px solid #ccc; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 16px; border: 1px solid #1f3a5f; border-radius: 4px; background: #1f3a5f; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #1f3a5f; }
        .sheet { position: relative; width: 210mm; min-height: 148mm; margin: 12px auto; padding: 12mm; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .15); page-break-after: always; }
        .sheet:last-child { page-break-after: auto; }
        .head { display: flex; align-items: center; justify-content: space-between; border-bottom: 2px solid #1f3a5f; padding-bottom: 4mm; margin-bottom: 5mm; }
        .head img { max-height: 16mm; max-width: 50mm; }
        .head .co { text-align: right; }
        .head .co b { display: block; font-size: 12pt; color: #1f3a5f; }
        h1 { font-size: 15pt; margin: 0 0 1mm; color: #1f3a5f; }
        .zh { color: #555; font-size: 8.5pt; }
        .who { display: grid; grid-template-columns: 1fr 1fr; gap: 1.5mm 10mm; margin-bottom: 5mm; }
        .who div span { display: inline-block; min-width: 34mm; color: #555; }
        .cols { display: grid; grid-template-columns: 1fr 1fr; gap: 8mm; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 1.6mm 1mm; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
        th { font-size: 9pt; text-transform: uppercase; letter-spacing: .3px; color: #1f3a5f; border-bottom: 1.5px solid #1f3a5f; }
        td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tr.sum td { font-weight: bold; border-top: 1.5px solid #333; border-bottom: none; }
        .net { margin-top: 6mm; display: flex; justify-content: space-between; align-items: center; padding: 4mm 5mm; background: #f3f5f9; border-inline-start: 4px solid #b08d4a; }
        .net b { font-size: 16pt; font-variant-numeric: tabular-nums; }
        .info { margin-top: 5mm; font-size: 8.5pt; color: #555; }
        .sign { margin-top: 12mm; display: flex; justify-content: space-between; gap: 20mm; }
        .sign div { flex: 1; border-top: 1px solid #333; padding-top: 1.5mm; font-size: 8.5pt; color: #555; }
        .watermark { position: absolute; top: 40%; left: 0; right: 0; text-align: center; font-size: 70pt; font-weight: bold; color: rgba(190, 0, 0, .12); transform: rotate(-25deg); pointer-events: none; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { width: auto; min-height: 0; margin: 0; padding: 0 0 8mm; box-shadow: none; }
            .net { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <button type="button" onclick="window.print()">Print</button>
    <a href="{{ route('payroll.show', $run) }}">Back</a>
</div>

@foreach($lines as $line)
    @php
        $e = $line->employee;
        $earnings = [
            ['Base salary', '基本工资', $line->base_salary],
            ['Allowances', '津贴', $line->allowances],
            ['Overtime'.((float) $line->overtime_hours > 0 ? ' ('.rtrim(rtrim((string) $line->overtime_hours, '0'), '.').' h)' : ''), '加班费', $line->overtime_pay],
            ['Bonus', '奖金', $line->bonus],
        ];
        $deductions = [
            ['Unpaid days'.((float) $line->unpaid_days > 0 ? ' ('.rtrim(rtrim((string) $line->unpaid_days, '0'), '.').')' : ''), '缺勤扣款', $line->unpaid_deduction],
            ['Social insurance', '社会保险（个人）', $line->social_insurance_employee],
            ['Housing fund', '住房公积金（个人）', $line->housing_fund_employee],
            ['Individual income tax', '个人所得税', $line->income_tax],
            ['Other deductions', '其他扣款', $line->other_deductions],
        ];
        $totalDeductions = Decimal::sum(array_map(fn ($d) => (string) $d[2], $deductions));
        $gross = Decimal::sum(array_map(fn ($d) => (string) $d[2], $earnings));
    @endphp
    <div class="sheet">
        @if($watermark)<div class="watermark">{{ $watermark }}</div>@endif
        <div class="head">
            <img src="{{ $logoUrl }}" alt="{{ $companyName }}">
            <div class="co"><b>{{ $companyName }}</b><span class="zh">{{ $run->isDraft() ? 'DRAFT' : $run->number }}</span></div>
        </div>
        <h1>Payslip · {{ $month }}</h1>
        <div class="zh" style="margin-bottom:4mm">工资单 · {{ $monthZh }}</div>
        <div class="who">
            <div><span>Employee <i class="zh">员工</i></span><b>{{ $e->name }}</b>@if($e->name_local) {{ $e->name_local }}@endif</div>
            <div><span>Code <i class="zh">工号</i></span>{{ $e->code }}</div>
            <div><span>Job title <i class="zh">职位</i></span>{{ $e->job_title ?: '—' }}</div>
            <div><span>Department <i class="zh">部门</i></span>{{ $e->department ? (\App\Models\Lookup::query()->where('list', 'department')->where('code', $e->department)->value('name') ?? $e->department) : '—' }}</div>
            <div><span>Pay day <i class="zh">发薪日</i></span>{{ $run->pay_date?->format('Y-m-d') ?? '—' }}</div>
            <div><span>Currency <i class="zh">币种</i></span>{{ $run->currency }}</div>
        </div>
        <div class="cols">
            <table>
                <thead><tr><th>Earnings <span class="zh">收入</span></th><th style="text-align:right">{{ $run->currency }}</th></tr></thead>
                <tbody>
                @foreach($earnings as [$en, $zh, $amount])
                    <tr><td>{{ $en }}<br><span class="zh">{{ $zh }}</span></td><td class="num">{{ $f($amount) }}</td></tr>
                @endforeach
                <tr class="sum"><td>Total earnings <span class="zh">收入合计</span></td><td class="num">{{ $f($gross) }}</td></tr>
                </tbody>
            </table>
            <table>
                <thead><tr><th>Deductions <span class="zh">扣款</span></th><th style="text-align:right">{{ $run->currency }}</th></tr></thead>
                <tbody>
                @foreach($deductions as [$en, $zh, $amount])
                    <tr><td>{{ $en }}<br><span class="zh">{{ $zh }}</span></td><td class="num">{{ $f($amount) }}</td></tr>
                @endforeach
                <tr class="sum"><td>Total deductions <span class="zh">扣款合计</span></td><td class="num">{{ $f($totalDeductions) }}</td></tr>
                </tbody>
            </table>
        </div>
        <div class="net"><span>Net pay <span class="zh">实发工资</span></span><b>{{ $f($line->net) }} {{ $run->currency }}</b></div>
        <div class="info">
            Paid by the company on top of your pay <span class="zh">单位缴纳</span>: social insurance {{ $f($line->social_insurance_employer) }} · housing fund {{ $f($line->housing_fund_employer) }} {{ $run->currency }}.
            @if($line->note)<br>Note <span class="zh">备注</span>: {{ $line->note }}@endif
        </div>
        <div class="sign"><div>Received by <span class="zh">签收</span></div><div>Date <span class="zh">日期</span></div></div>
    </div>
@endforeach
</body>
</html>
