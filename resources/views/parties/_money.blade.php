{{-- The Money tab: what the party paid or is owed. Amounts only for a user who may view payments
     ($money is null otherwise); the rows are those PaymentQueries::whereVisibleTo lets the user see
     (a sales user only their own clients' receipts; supplier payments only for those who see
     supplier prices). Totals count only payments that are effective (approved where needed). --}}
@php
    use App\Models\Payment;
    use App\Services\ProformaInvoices\PiAmounts;
    use Illuminate\Support\Facades\Route;
    use Illuminate\Support\HtmlString;

    $stack = fn (array $amounts) => new HtmlString(collect($amounts)->map(fn ($amount, $currency) => '<span class="app-money-stack__row app-num">'.e(PiAmounts::money($amount, $currency)).'</span>')->implode(''));
@endphp
<div class="app-card__head app-ptabs__head" id="money-head">
    <i class="bi bi-wallet2" aria-hidden="true"></i>{{ __('Money') }}
    @if($money && $money['recordKind'] && Route::has('payments.create'))
        <a href="{{ route('payments.create', ['kind' => $money['recordKind'], 'party' => $party->id]) }}" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-plus-lg" aria-hidden="true"></i>{{ __('Record a payment') }}</a>
    @endif
</div>

@if($money === null)
    <div class="app-card">
        <x-empty icon="lock" :title="__('Amounts are not shown to you')" :text="__('What this party paid or is owed is shown to people who can see payments. Ask a manager if you need it.')" />
    </div>
@else
    @php
        // Per currency: what came in, what went out, and (for a client) what open PIs still owe.
        $in = $money['totals']->filter(fn ($row) => bccomp((string) $row->money_in, '0', 4) !== 0)->mapWithKeys(fn ($row) => [$row->currency => (string) $row->money_in])->all();
        $out = $money['totals']->filter(fn ($row) => bccomp((string) $row->money_out, '0', 4) !== 0)->mapWithKeys(fn ($row) => [$row->currency => (string) $row->money_out])->all();
        $due = [];
        foreach ($money['pis'] as $pi) {
            $balance = PiAmounts::subtract($pi->total_amount, $pi->paid_amount);
            if (bccomp($balance, '0', 4) > 0) {
                $due[$pi->currency] = bcadd($due[$pi->currency] ?? '0', $balance, 4);
            }
        }
        $awaiting = $money['payments']->filter(fn ($p) => $money['queries']->status($p, $money['approvalRequired']) === 'awaiting')->count();
    @endphp

    <div class="app-money-kpis">
        @if($party->is_client && $money['pis']->isNotEmpty())
            <x-kpi :label="__('Still due on open PIs')" :value="$due ? $stack($due) : PiAmounts::money('0', $money['pis']->first()->currency)" icon="hourglass-split" :tone="$due ? 'due' : 'done'"
                   :hint="__('Open PIs: :count', ['count' => $money['pis']->count()])" />
        @endif
        @if($in || $party->is_client)
            <x-kpi :label="__('Received from them')" :value="$in ? $stack($in) : '—'" icon="box-arrow-in-down" tone="done" />
        @endif
        @if($out || ! $party->is_client)
            <x-kpi :label="__('Paid to them')" :value="$out ? $stack($out) : '—'" icon="box-arrow-up" tone="info" />
        @endif
        @if($awaiting > 0)
            <x-kpi :label="__('Awaiting approval')" :value="$awaiting" icon="hourglass" tone="late" :hint="__('Not counted until a manager approves them.')" />
        @endif
    </div>

    @if($party->is_client && $money['pis']->isNotEmpty())
        <x-section :title="__('Open proforma invoices')" icon="receipt" flush class="mb-3">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr>
                        <th scope="col">{{ __('PI number') }}</th><th scope="col">{{ __('Status') }}</th>
                        <th scope="col" class="text-end">{{ __('Total') }}</th><th scope="col" class="text-end">{{ __('Paid') }}</th><th scope="col" class="text-end">{{ __('Balance due') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($money['pis'] as $pi)
                        @php $balance = PiAmounts::subtract($pi->total_amount, $pi->paid_amount); @endphp
                        <tr>
                            <td>@if(Route::has('proforma-invoices.show'))<a href="{{ route('proforma-invoices.show', $pi) }}" class="app-ltr fw-semibold">{{ $pi->reference() }}</a>@else<span class="app-ltr">{{ $pi->reference() }}</span>@endif</td>
                            <td>@include('proforma-invoices._status', ['pi' => $pi])</td>
                            <td class="app-num text-end text-nowrap">{{ PiAmounts::money($pi->total_amount, $pi->currency) }}</td>
                            <td class="app-num text-end text-nowrap app-money-paid">{{ PiAmounts::money($pi->paid_amount, $pi->currency) }}
                                <x-progress :value="(float) $pi->paid_amount" :max="(float) $pi->total_amount" thin :label="__('Paid against total')" :tone="bccomp($balance, '0', 4) <= 0 ? 'done' : 'gold'" class="app-doc-list__bar" /></td>
                            <td class="app-num text-end text-nowrap fw-semibold">{{ PiAmounts::money($balance, $pi->currency) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </x-section>
    @endif

    <x-section :title="__('Payments')" icon="cash-coin" flush>
        @if($money['count'] > 0 && Route::has('payments.index'))
            <x-slot:actions><a href="{{ route('payments.index', ['party' => $party->id]) }}">{{ __('All payments for this party') }} <span class="app-num">({{ $money['count'] }})</span></a></x-slot:actions>
        @endif
        @if($money['payments']->isEmpty())
            <x-empty icon="cash-coin" :title="__('No payments yet.')" :text="__('Payments linked to this party appear here once they are recorded.')" />
        @else
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr>
                        <th scope="col">{{ __('Date') }}</th><th scope="col">{{ __('Number') }}</th><th scope="col">{{ __('Kind') }}</th>
                        <th scope="col">{{ __('Linked to') }}</th><th scope="col">{{ __('Account') }}</th><th scope="col" class="text-end">{{ __('Amount') }}</th>
                    </tr></thead>
                    <tbody>
                    @foreach($money['payments'] as $payment)
                        @php
                            $state = $money['queries']->status($payment, $money['approvalRequired']);
                            $incoming = $payment->direction === 'in';
                        @endphp
                        <tr @class(['app-doc-muted' => in_array($state, ['reversed', 'reversal'], true)])>
                            <td class="app-num text-nowrap">{{ $payment->paid_on?->toDateString() }}</td>
                            <td class="text-nowrap">@if(Route::has('payments.show'))<a href="{{ route('payments.show', $payment) }}" class="app-ltr">{{ $payment->number }}</a>@else<span class="app-ltr">{{ $payment->number }}</span>@endif</td>
                            <td>{{ Payment::kindLabel($payment->kind) }}
                                @if($state === 'awaiting')<x-status tone="due" class="ms-1">{{ __('Awaiting approval') }}</x-status>
                                @elseif($state === 'reversed')<x-status tone="skip" icon="arrow-counterclockwise" class="ms-1">{{ __('Reversed') }}</x-status>
                                @elseif($state === 'reversal')<x-status tone="skip" icon="arrow-counterclockwise" class="ms-1">{{ __('Reversal') }}</x-status>
                                @elseif($state === 'withdrawn')<x-status tone="skip" icon="slash-circle" class="ms-1">{{ __('Withdrawn') }}</x-status>
                                @elseif($state === 'withdrawal')<x-status tone="skip" icon="arrow-counterclockwise" class="ms-1">{{ __('Withdrawal') }}</x-status>@endif</td>
                            <td>
                                @if($payment->proformaInvoice && auth()->user()->can('proforma_invoices.view'))<span class="app-doc-code">{{ $payment->proformaInvoice->reference() }}</span>
                                @elseif($payment->supplierOrder)<span class="app-doc-code">{{ $payment->supplierOrder->number }}</span>
                                @elseif($payment->container)<span class="app-doc-code">{{ $payment->container->container_no ?: '#'.$payment->container->id }}</span>
                                @else — @endif
                            </td>
                            <td>{{ $payment->moneyAccount?->name }}</td>
                            <td class="app-num text-end text-nowrap fw-semibold">
                                <span class="app-money-dir {{ $incoming ? 'is-in' : 'is-out' }}"><i class="bi {{ $incoming ? 'bi-arrow-down-left' : 'bi-arrow-up-right' }}" aria-hidden="true"></i><span class="visually-hidden">{{ $incoming ? __('Money in') : __('Money out') }}</span></span>
                                {{ PiAmounts::money((string) $payment->signed_amount, $payment->currency) }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if($money['count'] > $money['payments']->count())
                <p class="app-doc-footnote"><i class="bi bi-info-circle" aria-hidden="true"></i>{{ __('The latest :count are shown.', ['count' => $money['payments']->count()]) }}</p>
            @endif
        @endif
    </x-section>
@endif
