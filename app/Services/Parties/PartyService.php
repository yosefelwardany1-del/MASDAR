<?php

namespace App\Services\Parties;

use App\Models\ClientStageChange;
use App\Models\Lookup;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\User;
use App\Services\Crm\CrmQueries;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only writer of parties and party_contacts (spec §3.1). A party's code is numbered from
 * the series of its first kind (client, then supplier, then shipping company) and never
 * changes. A party is never deleted: it is deactivated, because documents refer to it.
 */
class PartyService
{
    /** The columns a caller may set. The code, the creator and the id are this class's. */
    public const FIELDS = [
        'name', 'name_local', 'is_client', 'is_supplier', 'is_shipping', 'shipping_kind',
        'country', 'city', 'address', 'currency', 'account_manager_id', 'source', 'stage',
        'deposit_percent', 'default_destination', 'factory_address', 'products_note',
        'bank_details', 'notes', 'active', 'tracking_url_template',
    ];

    public const CONTACT_FIELDS = ['name', 'role', 'phone', 'whatsapp', 'wechat', 'email', 'is_primary', 'notes'];

    public function __construct(private NumberingService $numbers) {}

    /** @param array<string, mixed> $data validated party fields (no code: it is numbered here) */
    public function create(array $data, User $by): Party
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $kind = PartyKind::firstOf($data) ?? throw new InvalidArgumentException('A party must be a client, a supplier or a shipping company.');

        if (filled($data['stage'] ?? null)) {
            $this->assertTypedStage($data['stage']);
        }

        return DB::transaction(function () use ($data, $by, $kind) {
            $party = Party::create(array_merge($data, [
                'code' => $this->numbers->next($kind->series()),
                'created_by' => $by->id,
            ]));
            AuditTrail::record('created', $party, [], $this->auditable($party, array_merge(['code'], array_keys($data))));
            if ($party->is_client && filled($party->stage)) {
                $this->logStage($party, null, $party->stage, $by, null);
            }

            return $party;
        });
    }

    /** @param array<string, mixed> $data validated party fields; the code is never among them */
    public function update(Party $party, array $data, ?User $by = null): Party
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $stageChanges = array_key_exists('stage', $data) && (string) $data['stage'] !== (string) $party->stage;
        if ($stageChanges && filled($data['stage'])) {
            $this->assertTypedStage($data['stage']);
        }
        $by ??= Auth::user();
        $after = array_merge($party->only(['is_client', 'is_supplier', 'is_shipping']), $data);
        if (PartyKind::firstOf($after) === null) {
            throw new InvalidArgumentException('A party must be a client, a supplier or a shipping company.');
        }

        return DB::transaction(function () use ($party, $data, $stageChanges, $by) {
            $old = $this->auditable($party, array_keys($data));
            $fromStage = $party->stage;
            $party->update($data);
            if ($stageChanges && $party->is_client && $by) {
                $this->logStage($party, $fromStage, $party->stage, $by, null);
            }
            $new = $this->auditable($party, array_keys($data));
            $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
            if ($changed !== []) {
                AuditTrail::record('updated', $party, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)));
            }

            return $party;
        });
    }

    /**
     * Move a client to a stage a person sets (lead, inquiry, dormant …) from the pipeline
     * (spec §5.1). The stages that follow the documents are refused: they come from the PI,
     * the supplier orders and the containers.
     */
    public function moveStage(Party $party, string $stage, User $by, ?string $note = null): Party
    {
        if (! $party->is_client) {
            throw ValidationException::withMessages(['stage' => __('Only a client has a stage.')]);
        }
        $this->assertTypedStage($stage);

        return DB::transaction(function () use ($party, $stage, $by, $note) {
            $party = Party::query()->lockForUpdate()->findOrFail($party->id);
            $from = $party->stage;
            $party->update(['stage' => $stage]);
            $this->logStage($party, $from, $stage, $by, $note);
            AuditTrail::record('updated', $party, ['stage' => $from], ['stage' => $stage]);

            return $party;
        });
    }

    /** The only writer of client_stage_changes. */
    private function logStage(Party $party, ?string $from, ?string $to, User $by, ?string $note): void
    {
        ClientStageChange::create([
            'party_id' => $party->id, 'from_stage' => $from, 'to_stage' => $to, 'user_id' => $by->id,
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 2000) : null,
        ]);
    }

    /** A stage a person may set: on the list, active, and not one that follows the documents. */
    private function assertTypedStage(string $stage): void
    {
        $row = Lookup::query()->where('list', 'client_stage')->where('code', $stage)->where('active', true)->first();
        if (! $row) {
            throw ValidationException::withMessages(['stage' => __('Choose a stage from the list.')]);
        }
        if (CrmQueries::isDocumentStage($row->code, $row)) {
            throw ValidationException::withMessages(['stage' => __(':stage follows the documents: it is reached by issuing, confirming and shipping the client\'s PI, not set by hand.', ['stage' => $row->displayName()])]);
        }
    }

    public function deactivate(Party $party): Party
    {
        return $this->setActive($party, false, 'deactivated');
    }

    public function reactivate(Party $party): Party
    {
        return $this->setActive($party, true, 'reactivated');
    }

    /** @param array<string, mixed> $data validated contact fields */
    public function addContact(Party $party, array $data): PartyContact
    {
        $data = array_intersect_key($data, array_flip(self::CONTACT_FIELDS));

        return DB::transaction(function () use ($party, $data) {
            if (! empty($data['is_primary'])) {
                $this->clearPrimary($party);
            }
            $contact = $party->contacts()->create($data);
            AuditTrail::record('created', $contact, [], ['party_id' => $party->id] + $contact->only(array_keys($data)));

            return $contact;
        });
    }

    /** @param array<string, mixed> $data validated contact fields */
    public function updateContact(PartyContact $contact, array $data): PartyContact
    {
        $data = array_intersect_key($data, array_flip(self::CONTACT_FIELDS));

        return DB::transaction(function () use ($contact, $data) {
            if (! empty($data['is_primary']) && ! $contact->is_primary) {
                $this->clearPrimary($contact->party, $contact->id);
            }
            $old = $contact->only(array_keys($data));
            $contact->update($data);
            AuditTrail::record('updated', $contact, $old, $contact->only(array_keys($data)));

            return $contact;
        });
    }

    /** A contact is a person at the party, not a business record: removing one is a delete. */
    public function deleteContact(PartyContact $contact): void
    {
        DB::transaction(function () use ($contact) {
            $old = ['party_id' => $contact->party_id] + $contact->only(self::CONTACT_FIELDS);
            $contact->delete();
            AuditTrail::record('deleted', $contact, $old, []);
        });
    }

    private function setActive(Party $party, bool $active, string $action): Party
    {
        if ($party->active === $active) {
            return $party;
        }

        return DB::transaction(function () use ($party, $active, $action) {
            $party->update(['active' => $active]);
            AuditTrail::record($action, $party, ['active' => ! $active], ['active' => $active]);

            return $party;
        });
    }

    /** One primary contact per party: the new one takes the mark from the others. */
    private function clearPrimary(Party $party, ?int $except = null): void
    {
        $party->contacts()->getQuery()->reorder()
            ->where('is_primary', true)
            ->when($except, fn ($q) => $q->whereKeyNot($except))
            ->update(['is_primary' => false]);
    }

    /**
     * The audited values of $columns. Bank details are never copied into the audit log, which
     * people who may not see them can read (spec §6): the log says they changed, not to what.
     *
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function auditable(Party $party, array $columns): array
    {
        $values = $party->only($columns);
        if (array_key_exists('bank_details', $values)) {
            $values['bank_details'] = filled($values['bank_details']) ? '[hidden:'.substr(hash('sha256', (string) $values['bank_details']), 0, 8).']' : null;
        }

        return $values;
    }
}
