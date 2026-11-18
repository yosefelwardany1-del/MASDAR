<?php

namespace App\Services\Claims;

use App\Models\TradeNote;
use App\Services\Accounting\PostingService;
use Illuminate\Support\Facades\DB;

/**
 * Read-only figures from the issued credit and debit notes (spec §5.8), for every module that
 * asks what a client still owes on a PI or what MASDAR still owes a factory. A void or draft
 * note never counts. Use these, not your own SUM: "settled" on a PI is paid + credited.
 */
class NoteQueries
{
    /** Issued credit notes on any revision of $piNumber, in $currency. */
    public function creditedForPiNumber(string $piNumber, string $currency): string
    {
        return (string) DB::table('trade_notes as n')
            ->join('trade_note_lines as l', 'l.trade_note_id', '=', 'n.id')
            ->join('proforma_invoices as r', 'r.id', '=', 'n.proforma_invoice_id')
            ->where('n.kind', 'credit')->where('n.status', 'issued')
            ->where('r.pi_number', $piNumber)->where('n.currency', strtoupper($currency))
            ->selectRaw('COALESCE(SUM(l.amount), 0)::numeric(18,4) AS total')->value('total');
    }

    /** Issued debit notes on supplier order $orderId, in $currency. */
    public function debitedForOrder(int $orderId, string $currency): string
    {
        return (string) DB::table('trade_notes as n')
            ->join('trade_note_lines as l', 'l.trade_note_id', '=', 'n.id')
            ->where('n.kind', 'debit')->where('n.status', 'issued')
            ->where('n.supplier_order_id', $orderId)->where('n.currency', strtoupper($currency))
            ->selectRaw('COALESCE(SUM(l.amount), 0)::numeric(18,4) AS total')->value('total');
    }

    /**
     * SQL for the credited amount of the PI aliased $pi (its pi_number and currency), to use
     * inside a SELECT.
     */
    public static function creditedSql(string $pi = 'pi'): string
    {
        return "COALESCE((SELECT SUM(tnl.amount) FROM trade_notes tn
                    JOIN trade_note_lines tnl ON tnl.trade_note_id = tn.id
                    JOIN proforma_invoices tnr ON tnr.id = tn.proforma_invoice_id
                    WHERE tn.kind = 'credit' AND tn.status = 'issued'
                      AND tnr.pi_number = {$pi}.pi_number AND tn.currency = {$pi}.currency), 0)";
    }

    /** True when the note has an original posting that is not reversed. */
    public function isPosted(TradeNote $note): bool
    {
        return DB::table('journal_entries as e')
            ->where('e.source_type', PostingService::SOURCE_TRADE_NOTE)->where('e.source_id', $note->id)
            ->whereNull('e.reverses_entry_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as r')->whereColumn('r.reverses_entry_id', 'e.id'))
            ->exists();
    }
}
