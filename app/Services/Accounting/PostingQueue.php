<?php

namespace App\Services\Accounting;

use App\Models\Payment;
use App\Models\ProformaInvoice;
use App\Models\SupplierOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * What is waiting to be posted (the "To post" screen). Read-only: posting goes through
 * PostingService.
 */
class PostingQueue
{
    public function __construct(private Ledger $ledger) {}

    /** Effective payments (phase-1 contract) with no entry yet; originals before their reversals. */
    public function payments(): Collection
    {
        return $this->ledger->effectivePayments()
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as e')
                ->where('e.source_type', PostingService::SOURCE_PAYMENT)->whereColumn('e.source_id', 'payments.id'))
            ->with('moneyAccount', 'party', 'reverses')
            ->orderBy('paid_on')->orderBy('id')
            ->get();
    }

    /** Outgoing payments not posted because they still wait for a manager's approval. */
    public function awaitingApproval(): int
    {
        return Payment::query()->whereNotIn('id', $this->ledger->effectivePayments()->select('payments.id'))->count();
    }

    /** Confirmed, current PIs with no client_invoice yet — on this revision or, unreversed, on another. */
    public function invoices(): Collection
    {
        return ProformaInvoice::query()->current()->where('status', 'confirmed')
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as e')
                ->where('e.source_type', PostingService::SOURCE_PI)->where('e.event', 'client_invoice')
                ->whereColumn('e.source_id', 'proforma_invoices.id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as e')
                ->join('proforma_invoices as other', 'other.id', '=', 'e.source_id')
                ->where('e.source_type', PostingService::SOURCE_PI)->where('e.event', 'client_invoice')
                ->whereNull('e.reverses_entry_id')
                ->whereColumn('other.pi_number', 'proforma_invoices.pi_number')
                ->whereNotExists(fn (Builder $r) => $r->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id')))
            ->with('client')
            ->withSum('lines', 'amount')
            ->orderBy('confirmed_at')->orderBy('id')
            ->get();
    }

    /** Supplier orders whose every line is fully received, not cancelled, with no supplier_bill yet. */
    public function bills(): Collection
    {
        return SupplierOrder::query()->where('status', '<>', 'cancelled')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('supplier_order_lines as l')->whereColumn('l.supplier_order_id', 'supplier_orders.id'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('supplier_order_lines as l')
                ->whereColumn('l.supplier_order_id', 'supplier_orders.id')
                ->whereRaw('l.cartons > (SELECT COALESCE(SUM(g.cartons), 0) FROM goods_receipts g WHERE g.supplier_order_line_id = l.id)'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('journal_entries as e')
                ->where('e.source_type', PostingService::SOURCE_SUPPLIER_ORDER)->where('e.event', 'supplier_bill')
                ->whereColumn('e.source_id', 'supplier_orders.id'))
            ->with('supplier')
            ->withSum('lines', 'amount')
            ->addSelect(['received_on' => fn (Builder $q) => $q->selectRaw('MAX(g.received_on)')->from('goods_receipts as g')
                ->join('supplier_order_lines as l', 'l.id', '=', 'g.supplier_order_line_id')
                ->whereColumn('l.supplier_order_id', 'supplier_orders.id')])
            ->orderBy('id')
            ->get();
    }
}
