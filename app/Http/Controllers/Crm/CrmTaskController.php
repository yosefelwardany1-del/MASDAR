<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\CrmTask;
use App\Models\Party;
use App\Models\User;
use App\Services\Crm\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * My follow-up tasks (screen `crm`, spec §5.1): open tasks, overdue first. A task about a party
 * is seen only by someone who may open that party (CrmTask::scopeVisibleTo), so the own-clients
 * rule also covers the task list. Writing needs `crm.edit` (route middleware).
 */
class CrmTaskController extends Controller
{
    public const FILTERS = ['mine', 'all', 'done'];

    public function __construct(private CrmService $crm) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filter = in_array($request->query('filter'), self::FILTERS, true) ? $request->query('filter') : 'mine';

        $tasks = CrmTask::query()->visibleTo($user)->with(['party', 'assignee', 'doneBy'])
            ->when($filter === 'mine', fn ($q) => $q->open()->where('assigned_to', $user->id)->orderBy('due_on')->orderBy('id'))
            ->when($filter === 'all', fn ($q) => $q->open()->orderBy('due_on')->orderBy('id'))
            ->when($filter === 'done', fn ($q) => $q->whereNotNull('done_at')->orderByDesc('done_at'))
            ->paginate(30)->withQueryString();

        $canEdit = $user->can('crm.edit');

        return view('crm.tasks.index', [
            'tasks' => $tasks,
            'filter' => $filter,
            'overdueCount' => CrmTask::query()->open()->where('assigned_to', $user->id)->where('due_on', '<', today())->count(),
            'users' => $canEdit ? User::where('status', 'active')->orderBy('name')->get(['id', 'name']) : collect(),
            'parties' => $canEdit ? Party::query()->visibleTo($user)->where('active', true)->orderBy('name')->get(['id', 'code', 'name']) : collect(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validateWithBag('task', [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['required', 'date'],
            'assigned_to' => ['required', 'integer', Rule::exists('users', 'id')->where('status', 'active')],
            'party_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['party_id'])) {
            // A party the user may not open is answered as if it did not exist.
            abort_unless(Party::query()->visibleTo($user)->whereKey($data['party_id'])->exists(), 404);
        }

        $this->crm->createTask($data, $user);

        return back()->with('success', __('Task added.'));
    }

    public function complete(Request $request, string $task): RedirectResponse
    {
        $this->crm->completeTask($this->find($request, $task), $request->user());

        return back()->with('success', __('Task done.'));
    }

    public function reopen(Request $request, string $task): RedirectResponse
    {
        $this->crm->reopenTask($this->find($request, $task), $request->user());

        return back()->with('success', __('Task reopened.'));
    }

    private function find(Request $request, string $id): CrmTask
    {
        abort_unless(ctype_digit($id), 404);

        return CrmTask::query()->visibleTo($request->user())->findOrFail((int) $id);
    }
}
