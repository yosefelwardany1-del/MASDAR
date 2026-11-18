<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetting;
use App\Models\CrmInteraction;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\User;
use App\Services\Crm\CrmQueries;
use App\Services\Crm\CrmService;
use App\Services\Parties\PartyService;
use App\Support\Access\Visibility;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The CRM (screen `crm`, spec §5.1): its home (where the clients are, who has gone quiet, what is
 * due, how clients find MASDAR), the pipeline of clients by stage, and every contact with every
 * party. What a user sees follows the party rules (a sales user limited to their own clients).
 */
class CrmController extends Controller
{
    public function __construct(private CrmQueries $queries) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $pipeline = $this->queries->pipeline($user);
        $stages = $this->queries->stages();

        return view('crm.index', [
            'kpis' => $this->queries->kpis($user, $pipeline),
            'stages' => $stages,
            'funnel' => collect($stages)->map(fn ($s, $code) => $pipeline->where('crm_stage', $code)->count()),
            'unstaged' => $pipeline->whereNull('crm_stage')->count(),
            'quiet' => $this->queries->quiet($user, $pipeline)->take(8),
            'quietDays' => BusinessSetting::current()->crm_quiet_days,
            'sources' => $this->queries->sources($user),
            'recent' => $this->queries->activity($user)->limit(8)->get(),
            'team' => Visibility::ownClientsOnly($user) ? collect() : $this->queries->team(),
            'channels' => Lookup::options('interaction_channel'),
        ]);
    }

    public function pipeline(Request $request)
    {
        $user = $request->user();
        $manager = $request->integer('manager') ?: null;

        return view('crm.pipeline', [
            'stages' => $this->queries->stages(),
            'clients' => $this->queries->pipeline($user, $manager),
            'manager' => $manager,
            'managers' => Visibility::ownClientsOnly($user) ? collect() : User::query()
                ->whereIn('id', Party::query()->clients()->whereNotNull('account_manager_id')->select('account_manager_id'))
                ->orderBy('name')->get(['id', 'name']),
            'canMove' => $user->can('clients.edit'),
        ]);
    }

    public function move(Request $request, PartyService $parties): RedirectResponse
    {
        $data = $request->validate([
            'party_id' => ['required', 'integer'],
            'stage' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $client = $this->queries->clients($request->user())->findOrFail($data['party_id']);
        $parties->moveStage($client, $data['stage'], $request->user(), $data['note'] ?? null);

        return back()->with('success', __(':name moved to :stage.', ['name' => $client->name, 'stage' => Lookup::label('client_stage', $data['stage'])]));
    }

    public function activity(Request $request)
    {
        $user = $request->user();
        $filters = [
            'user' => $request->integer('user') ?: null,
            'channel' => (string) $request->query('channel', ''),
            'kind' => in_array($request->query('kind'), ['client', 'supplier', 'shipping'], true) ? $request->query('kind') : '',
            'from' => $this->date($request->query('from')),
            'to' => $this->date($request->query('to')),
        ];
        $column = ['client' => 'is_client', 'supplier' => 'is_supplier', 'shipping' => 'is_shipping'][$filters['kind']] ?? null;

        $interactions = $this->queries->activity($user)
            ->when($filters['user'], fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['channel'] !== '', fn ($q) => $q->where('channel', $filters['channel']))
            ->when($column, fn ($q, $c) => $q->whereIn('party_id', Party::query()->where($c, true)->select('id')))
            ->when($filters['from'], fn ($q, $d) => $q->where('happened_at', '>=', $d))
            ->when($filters['to'], fn ($q, $d) => $q->where('happened_at', '<', CarbonImmutable::parse($d)->addDay()))
            ->paginate(30)->withQueryString();

        return view('crm.activity', [
            'interactions' => $interactions,
            'filters' => $filters,
            'channels' => Lookup::options('interaction_channel'),
            'people' => User::query()->whereIn('id', CrmInteraction::query()->select('user_id'))->orderBy('name')->get(['id', 'name']),
            'parties' => $user->can('crm.edit') ? Party::query()->visibleTo($user)->where('active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
        ]);
    }

    public function storeActivity(Request $request, CrmService $crm): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('interaction', [
            'party_id' => ['required', 'integer'],
            'channel' => ['required', 'string', Rule::in(array_keys(Lookup::options('interaction_channel')))],
            'happened_at' => ['required', 'date'],
            'summary' => ['required', 'string', 'max:5000'],
            'next_step' => ['nullable', 'string', 'max:255'],
            'next_step_due' => ['nullable', 'date', 'required_with:next_step'],
        ]);
        $party = Party::query()->visibleTo($user)->findOrFail($data['party_id']);
        $crm->recordInteraction($party, $data, $user);

        return redirect()->route('crm.activity')->with('success', __('Contact with :name logged.', ['name' => $party->name]));
    }

    private function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
    }
}
