<?php

namespace App\Services\Crm;

use App\Models\BusinessSetting;
use App\Models\CrmInteraction;
use App\Models\CrmTask;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The CRM as read (spec §5.1). A client's stage is the latest of two signals: the stage a person
 * set (lead, inquiry, dormant …, logged in client_stage_changes) and the stage its latest PI has
 * reached in the documents — PI sent, confirmed, in production (a factory order), shipped (its
 * goods on board), delivered (every container with its goods delivered). Read-only.
 */
class CrmQueries
{
    /** Document stages, most advanced first. */
    private const DOCUMENT_STAGES = ['delivered', 'shipped', 'in_production', 'confirmed', 'pi_sent'];

    /** @var array<string, array{label: string, from_documents: bool, closed: bool}>|null */
    private ?array $stages = null;

    /** Active client stages in their order. @return array<string, array{label: string, from_documents: bool, closed: bool}> */
    public function stages(): array
    {
        return $this->stages ??= Lookup::query()->ofList('client_stage')->where('active', true)->get()
            ->mapWithKeys(fn (Lookup $l) => [$l->code => [
                'label' => $l->displayName(),
                'from_documents' => (int) ($l->meta['from_documents'] ?? 0) === 1,
                'closed' => (int) ($l->meta['closed'] ?? 0) === 1,
            ]])->all();
    }

    /** The clients $user may see. */
    public function clients(User $user): Builder
    {
        return Party::query()->clients()->visibleTo($user);
    }

    /**
     * Each client's stage now, and since when, and whether a person or the documents set it.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{stage: ?string, since: ?CarbonImmutable, by: string}>
     */
    public function stagesFor(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }
        $stages = $this->stages();
        $result = [];

        // What a person set, and when.
        $typed = DB::table('parties')->whereIn('parties.id', $clientIds)
            ->leftJoinSub(DB::table('client_stage_changes')->selectRaw('party_id, MAX(created_at) AS at')->groupBy('party_id'), 'sc', 'sc.party_id', '=', 'parties.id')
            ->get(['parties.id', 'parties.stage', 'parties.created_at', 'sc.at']);
        foreach ($typed as $row) {
            $result[$row->id] = [
                'stage' => $row->stage !== null && isset($stages[$row->stage]) ? $row->stage : null,
                'since' => ($row->at ?? $row->created_at) ? CarbonImmutable::parse($row->at ?? $row->created_at) : null,
                'by' => 'person',
            ];
        }

        foreach ($this->documentStages($clientIds) as $clientId => [$stage, $since]) {
            $current = $result[$clientId] ?? ['stage' => null, 'since' => null];
            if ($current['stage'] === null || $current['since'] === null || $since->greaterThanOrEqualTo($current['since'])) {
                $result[$clientId] = ['stage' => $stage, 'since' => $since, 'by' => 'documents'];
            }
        }

        return $result;
    }

    /**
     * The stage each client's latest live PI has reached in the documents.
     *
     * @param  list<int>  $clientIds
     * @return array<int, array{0: string, 1: CarbonImmutable}>
     */
    private function documentStages(array $clientIds): array
    {
        $available = array_filter(self::DOCUMENT_STAGES, fn ($code) => isset($this->stages()[$code]));
        if ($available === []) {
            return [];
        }
        $ids = implode(',', array_map('intval', $clientIds));
        $pis = DB::select(<<<SQL
            SELECT DISTINCT ON (client_id) client_id, pi_number, status, issued_at, accepted_at, confirmed_at
            FROM proforma_invoices
            WHERE client_id IN ({$ids}) AND is_current AND status IN ('issued', 'accepted', 'confirmed')
            ORDER BY client_id, COALESCE(confirmed_at, accepted_at, issued_at) DESC NULLS LAST, id DESC
        SQL);
        if ($pis === []) {
            return [];
        }
        $numbers = array_map(fn ($p) => $p->pi_number, $pis);
        $in = implode(',', array_fill(0, count($numbers), '?'));

        // When the first factory order of each PI (any revision) was placed.
        $ordered = collect(DB::select(<<<SQL
            SELECT p.pi_number, MAX(so.created_at) AS at
            FROM supplier_order_lines sol
            JOIN supplier_orders so ON so.id = sol.supplier_order_id AND so.status <> 'cancelled'
            JOIN pi_lines pl ON pl.id = sol.pi_line_id
            JOIN proforma_invoices p ON p.id = pl.proforma_invoice_id
            WHERE sol.released_at IS NULL AND p.pi_number IN ({$in})
            GROUP BY p.pi_number
        SQL, $numbers))->keyBy('pi_number');

        // Each container holding goods of the PI, with its latest status.
        $containers = collect(DB::select(<<<SQL
            SELECT DISTINCT ON (p.pi_number, cl.container_id) p.pi_number, cl.container_id, ev.status, ev.happened_at
            FROM container_loads cl
            JOIN supplier_order_lines sol ON sol.id = cl.supplier_order_line_id
            JOIN pi_lines pl ON pl.id = sol.pi_line_id
            JOIN proforma_invoices p ON p.id = pl.proforma_invoice_id
            JOIN LATERAL (SELECT e.status, e.happened_at FROM container_events e WHERE e.container_id = cl.container_id ORDER BY e.id DESC LIMIT 1) ev ON true
            WHERE p.pi_number IN ({$in})
              AND (SELECT COALESCE(SUM(x.cartons), 0) FROM container_loads x
                   WHERE x.container_id = cl.container_id AND x.supplier_order_line_id = cl.supplier_order_line_id) > 0
            ORDER BY p.pi_number, cl.container_id
        SQL, $numbers))->groupBy('pi_number');

        $statuses = Lookup::query()->ofList('container_status')->get(['code', 'sort', 'meta'])->keyBy('code');
        $onBoard = $statuses->get('on_board')?->sort;

        $out = [];
        foreach ($pis as $pi) {
            $candidates = [];
            $boxes = $containers->get($pi->pi_number, collect());
            if ($boxes->isNotEmpty()) {
                $delivered = $boxes->every(fn ($c) => (int) ($statuses->get($c->status)?->meta['final'] ?? 0) === 1);
                if ($delivered) {
                    $candidates['delivered'] = $boxes->max('happened_at');
                }
                $shipped = $onBoard === null ? collect() : $boxes->filter(fn ($c) => ($statuses->get($c->status)?->sort ?? -1) >= $onBoard);
                if ($shipped->isNotEmpty()) {
                    $candidates['shipped'] = $shipped->max('happened_at');
                }
            }
            if ($pi->status === 'confirmed') {
                if ($at = $ordered->get($pi->pi_number)?->at) {
                    $candidates['in_production'] = $at;
                }
                $candidates['confirmed'] = $pi->confirmed_at;
            }
            $candidates['pi_sent'] = $pi->accepted_at ?? $pi->issued_at;

            foreach ($available as $code) {
                if (isset($candidates[$code]) && $candidates[$code] !== null) {
                    $out[$pi->client_id] = [$code, CarbonImmutable::parse($candidates[$code])];
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * The pipeline: every visible, active client with its stage and what a salesperson needs
     * on the card.
     *
     * @return Collection<int, object>
     */
    public function pipeline(User $user, ?int $accountManagerId = null): Collection
    {
        $clients = $this->clients($user)->where('active', true)
            ->when($accountManagerId, fn ($q, $id) => $q->where('account_manager_id', $id))
            ->with('accountManager:id,name')
            ->addSelect(['last_contact_at' => CrmInteraction::query()->selectRaw('MAX(happened_at)')->whereColumn('party_id', 'parties.id')])
            ->addSelect(['next_task_on' => CrmTask::query()->selectRaw('MIN(due_on)')->whereColumn('party_id', 'parties.id')->whereNull('done_at')])
            ->addSelect(['open_pis' => DB::table('proforma_invoices')->selectRaw('COUNT(*)')->whereColumn('client_id', 'parties.id')
                ->where('is_current', true)->whereIn('status', ['issued', 'accepted', 'confirmed'])])
            ->orderBy('name')
            ->get();
        $stages = $this->stagesFor($clients->pluck('id')->all());

        return $clients->map(function (Party $client) use ($stages) {
            $client->setAttribute('crm_stage', $stages[$client->id]['stage'] ?? null);
            $client->setAttribute('crm_stage_since', $stages[$client->id]['since'] ?? null);
            $client->setAttribute('crm_stage_by', $stages[$client->id]['by'] ?? 'person');

            return $client;
        });
    }

    /**
     * Clients nobody has contacted for the quiet days (or never, once older than that), in a
     * stage that is still chased. @return Collection<int, Party>
     */
    public function quiet(User $user, ?Collection $pipeline = null): Collection
    {
        $days = BusinessSetting::current()->crm_quiet_days;
        $cutoff = now()->subDays($days);
        $closed = array_keys(array_filter($this->stages(), fn ($s) => $s['closed']));

        return ($pipeline ?? $this->pipeline($user))
            ->filter(fn (Party $c) => ! in_array($c->crm_stage, $closed, true))
            ->filter(fn (Party $c) => $c->last_contact_at ? CarbonImmutable::parse($c->last_contact_at)->lt($cutoff) : $c->created_at->lt($cutoff))
            ->sortBy(fn (Party $c) => $c->last_contact_at ?? '0000')
            ->values();
    }

    /** Headline figures for the CRM home. @return array<string, int> */
    public function kpis(User $user, Collection $pipeline): array
    {
        $visible = $this->clients($user)->select('parties.id');

        return [
            'new_clients' => $this->clients($user)->where('created_at', '>=', now()->subDays(30))->count(),
            'contacts_week' => CrmInteraction::query()->whereIn('party_id', Party::query()->visibleTo($user)->select('parties.id'))
                ->where('happened_at', '>=', now()->subDays(7))->count(),
            'overdue' => CrmTask::query()->visibleTo($user)->open()->where('due_on', '<', today())->count(),
            'quiet' => $this->quiet($user, $pipeline)->count(),
            'won_90' => DB::table('proforma_invoices')->whereIn('client_id', $visible)->where('is_current', true)
                ->whereNotNull('confirmed_at')->where('confirmed_at', '>=', now()->subDays(90))->count(),
        ];
    }

    /** How clients found MASDAR, and how many of them have confirmed an order. @return Collection<int, object> */
    public function sources(User $user): Collection
    {
        $labels = Lookup::options('party_source');

        return $this->clients($user)->toBase()
            ->selectRaw("COALESCE(source, '') AS source, COUNT(*) AS clients")
            ->selectRaw('COUNT(*) FILTER (WHERE EXISTS (SELECT 1 FROM proforma_invoices p WHERE p.client_id = parties.id AND p.confirmed_at IS NOT NULL)) AS won')
            ->groupByRaw("COALESCE(source, '')")->orderByDesc('clients')->get()
            ->map(fn ($row) => (object) ['source' => $row->source, 'label' => $row->source === '' ? __('Not recorded') : ($labels[$row->source] ?? $row->source),
                'clients' => (int) $row->clients, 'won' => (int) $row->won]);
    }

    /** The latest contacts with the parties $user may see. */
    public function activity(User $user): Builder
    {
        return CrmInteraction::query()->whereIn('party_id', Party::query()->visibleTo($user)->select('parties.id'))
            ->with(['party:id,code,name,is_client,is_supplier,is_shipping', 'user:id,name'])
            ->orderByDesc('happened_at')->orderByDesc('id');
    }

    /** Contacts per person over the last $days days (for whoever sees every client). @return Collection<int, object> */
    public function team(int $days = 30): Collection
    {
        return DB::table('crm_interactions as i')->join('users as u', 'u.id', '=', 'i.user_id')
            ->where('i.happened_at', '>=', now()->subDays($days))
            ->groupBy('u.id', 'u.name')->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['u.id', 'u.name', DB::raw('COUNT(*) AS contacts'), DB::raw('COUNT(DISTINCT i.party_id) AS parties')]);
    }
}
