<?php

namespace App\Services\Dashboard;

use App\Models\BusinessSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What needs someone's attention today (spec §7), read straight from the tables: late and
 * due-soon order steps, containers late or arriving soon, open tasks. Read-only; used by the
 * home page and the daily alerts. Every date is in APP_TIMEZONE (China time).
 */
class AttentionQueries
{
    /**
     * Steps of open supplier orders that are past due, or due within the warning days, and not
     * done or skipped. Each row: order_id, order_number, merchandiser_id, step_name fields, due_on, late.
     */
    public function steps(?int $merchandiserId = null, int $limit = 50): Collection
    {
        $today = Carbon::today()->toDateString();
        $warn = Carbon::today()->addDays(BusinessSetting::current()->step_warning_days)->toDateString();

        $rows = DB::select(<<<'SQL'
            SELECT so.id AS order_id, so.number AS order_number, so.merchandiser_id, so.created_by,
                   d.id AS step_id, d.name, d.name_ar, d.name_zh,
                   (so.delivery_date - d.due_offset_days) AS due_on,
                   (so.delivery_date - d.due_offset_days) < CAST(? AS date) AS late
            FROM supplier_orders so
            JOIN order_step_definitions d ON d.active AND d.due_offset_days IS NOT NULL
            LEFT JOIN LATERAL (
                SELECT e.status FROM supplier_order_step_events e
                WHERE e.supplier_order_id = so.id AND e.step_definition_id = d.id
                ORDER BY e.id DESC LIMIT 1
            ) last ON true
            WHERE so.status = 'open' AND so.delivery_date IS NOT NULL
              AND (last.status IS NULL OR last.status = 'reopened')
              AND (so.delivery_date - d.due_offset_days) <= CAST(? AS date)
              AND (CAST(? AS bigint) IS NULL OR so.merchandiser_id = CAST(? AS bigint))
            ORDER BY due_on, so.number, d.sort
            LIMIT ?
        SQL, [$today, $warn, $merchandiserId, $merchandiserId, $limit]);

        return collect($rows)->map(function ($row) {
            $row->step_label = match (app()->getLocale()) {
                'ar' => $row->name_ar ?: $row->name,
                'zh_CN' => $row->name_zh ?: $row->name,
                default => $row->name,
            };

            return $row;
        });
    }

    /**
     * Containers not yet arrived whose ETA has passed or falls within the warning days. Each
     * row: container_id, container_no, shipment_number, eta, status, late, days.
     */
    public function containers(?int $accountManagerId = null, int $limit = 50): Collection
    {
        $today = Carbon::today()->toDateString();
        $warn = Carbon::today()->addDays(BusinessSetting::current()->eta_warning_days)->toDateString();

        return collect(DB::select(<<<'SQL'
            SELECT c.id AS container_id, c.container_no, s.number AS shipment_number, s.eta,
                   last.status, s.eta < CAST(? AS date) AS late, (s.eta - CAST(? AS date)) AS days
            FROM containers c
            JOIN shipments s ON s.id = c.shipment_id
            LEFT JOIN LATERAL (
                SELECT e.status FROM container_events e WHERE e.container_id = c.id ORDER BY e.id DESC LIMIT 1
            ) last ON true
            LEFT JOIN lookups st ON st.list = 'container_status' AND st.code = last.status
            WHERE s.eta IS NOT NULL AND s.eta <= CAST(? AS date)
              AND COALESCE(st.sort, 0) < COALESCE((SELECT sort FROM lookups WHERE list = 'container_status' AND code = 'arrived'), 2147483647)
              AND (CAST(? AS bigint) IS NULL OR EXISTS (
                    SELECT 1 FROM container_loads cl
                    JOIN supplier_order_lines sol ON sol.id = cl.supplier_order_line_id
                    JOIN pi_lines pl ON pl.id = sol.pi_line_id
                    JOIN proforma_invoices pi ON pi.id = pl.proforma_invoice_id
                    JOIN parties p ON p.id = pi.client_id
                    WHERE cl.container_id = c.id AND p.account_manager_id = CAST(? AS bigint)))
            ORDER BY s.eta, c.id
            LIMIT ?
        SQL, [$today, $today, $warn, $accountManagerId, $accountManagerId, $limit]));
    }

    /** Open tasks due today or earlier for $userId. */
    public function tasks(int $userId, int $limit = 20): Collection
    {
        return DB::table('crm_tasks')
            ->leftJoin('parties', 'parties.id', '=', 'crm_tasks.party_id')
            ->where('assigned_to', $userId)->whereNull('done_at')
            ->whereDate('due_on', '<=', Carbon::today())
            ->orderBy('due_on')->limit($limit)
            ->get(['crm_tasks.id', 'crm_tasks.title', 'crm_tasks.due_on', 'parties.name as party_name']);
    }

    /** Headline counts for the home page. */
    public function counts(): array
    {
        $arrivedSort = DB::table('lookups')->where('list', 'container_status')->where('code', 'arrived')->value('sort') ?? 2147483647;

        return [
            'open_pis' => DB::table('proforma_invoices')->where('is_current', true)->whereIn('status', ['issued', 'accepted', 'confirmed'])->count(),
            'draft_pis' => DB::table('proforma_invoices')->where('is_current', true)->where('status', 'draft')->count(),
            'open_orders' => DB::table('supplier_orders')->where('status', 'open')->count(),
            'late_steps' => $this->steps(limit: 1000)->where('late', true)->count(),
            'containers_moving' => (int) DB::selectOne(<<<'SQL'
                SELECT COUNT(*) AS n FROM containers c
                JOIN LATERAL (SELECT e.status FROM container_events e WHERE e.container_id = c.id ORDER BY e.id DESC LIMIT 1) last ON true
                JOIN lookups st ON st.list = 'container_status' AND st.code = last.status
                WHERE st.sort < ?
            SQL, [$arrivedSort])->n,
            'awaiting_approval' => DB::table('payments')->where('direction', 'out')->whereNull('reverses_payment_id')
                ->whereNotExists(fn ($q) => $q->from('payment_approvals')->whereColumn('payment_approvals.payment_id', 'payments.id'))
                ->whereNotExists(fn ($q) => $q->from('payments as r')->whereColumn('r.reverses_payment_id', 'payments.id'))
                ->count(),
        ];
    }
}
