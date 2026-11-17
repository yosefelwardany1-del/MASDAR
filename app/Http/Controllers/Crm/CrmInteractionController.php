<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Services\Crm\CrmService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Records an interaction on a party's page (spec §3.7). Interactions are append-only: there is
 * no edit and no delete. Needs `crm.edit` (route middleware) AND a party the user may open.
 */
class CrmInteractionController extends Controller
{
    public function __construct(private CrmService $crm) {}

    public function store(Request $request, string $party): RedirectResponse
    {
        abort_unless(ctype_digit($party), 404);
        $model = Party::query()->visibleTo($request->user())->findOrFail((int) $party);

        $data = $request->validateWithBag('interaction', [
            'channel' => ['required', 'string', Rule::exists('lookups', 'code')->where('list', 'interaction_channel')],
            'happened_at' => ['required', 'date', 'before_or_equal:'.now()->addDay()->toDateTimeString()],
            'summary' => ['required', 'string', 'max:5000'],
            'next_step' => ['nullable', 'string', 'max:2000'],
            'next_step_due' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $this->crm->recordInteraction($model, $data, $request->user());

        return redirect()->to(url()->previous().'#crm')->with('success', __('Interaction recorded.'));
    }
}
