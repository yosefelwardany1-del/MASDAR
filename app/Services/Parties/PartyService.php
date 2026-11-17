<?php

namespace App\Services\Parties;

use App\Models\Party;
use App\Models\PartyContact;
use App\Models\User;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
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
        'bank_details', 'notes', 'active',
    ];

    public const CONTACT_FIELDS = ['name', 'role', 'phone', 'whatsapp', 'wechat', 'email', 'is_primary', 'notes'];

    public function __construct(private NumberingService $numbers) {}

    /** @param array<string, mixed> $data validated party fields (no code: it is numbered here) */
    public function create(array $data, User $by): Party
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $kind = PartyKind::firstOf($data) ?? throw new InvalidArgumentException('A party must be a client, a supplier or a shipping company.');

        return DB::transaction(function () use ($data, $by, $kind) {
            $party = Party::create(array_merge($data, [
                'code' => $this->numbers->next($kind->series()),
                'created_by' => $by->id,
            ]));
            AuditTrail::record('created', $party, [], $this->auditable($party, array_merge(['code'], array_keys($data))));

            return $party;
        });
    }

    /** @param array<string, mixed> $data validated party fields; the code is never among them */
    public function update(Party $party, array $data): Party
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        $after = array_merge($party->only(['is_client', 'is_supplier', 'is_shipping']), $data);
        if (PartyKind::firstOf($after) === null) {
            throw new InvalidArgumentException('A party must be a client, a supplier or a shipping company.');
        }

        return DB::transaction(function () use ($party, $data) {
            $old = $this->auditable($party, array_keys($data));
            $party->update($data);
            $new = $this->auditable($party, array_keys($data));
            $changed = array_keys(array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
            if ($changed !== []) {
                AuditTrail::record('updated', $party, array_intersect_key($old, array_flip($changed)), array_intersect_key($new, array_flip($changed)));
            }

            return $party;
        });
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
