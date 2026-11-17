<?php

namespace App\Support\IO\Resources;

use App\Models\Party;
use App\Models\User;
use App\Services\Parties\PartyKind;
use App\Services\Parties\PartyService;
use App\Support\IO\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Import/export of one kind of party, written through PartyService. The screen of the kind
 * gates it (`{screen}.view` to export, `{screen}.edit` to import), and the same data rules as
 * the screen apply: a sales user who sees only their own clients exports and updates only those,
 * and manages every client they import.
 */
abstract class PartySchema extends ServiceBackedSchema
{
    abstract protected function kind(): PartyKind;

    public function model(): string
    {
        return Party::class;
    }

    public function permissionModule(): string
    {
        return $this->kind()->screen();
    }

    public function label(): string
    {
        return __($this->kind()->plural());
    }

    public function exportWith(): array
    {
        return ['accountManager'];
    }

    public function scopeExportQuery(Builder $query, User $user): Builder
    {
        return $this->scoped($query->where($this->kind()->column(), true)->orderBy('code'), $user);
    }

    public function findForImport(string $code, User $user): ?Model
    {
        return $this->scoped(Party::query()->where($this->kind()->column(), true), $user)->where('code', $code)->first();
    }

    public function writeRow(array $attrs, ?Model $existing, User $user): Model
    {
        $service = app(PartyService::class);
        if (array_key_exists('active', $attrs) && $attrs['active'] === null) {
            unset($attrs['active']);
        }

        return $existing
            ? $service->update($existing, $attrs)
            : $service->create($attrs + [$this->kind()->column() => true], $user);
    }

    /** The columns every kind shares. @return list<Field> */
    protected function commonFields(): array
    {
        return [
            new Field('code', false, 'Empty to add a new one; an existing code to update it'),
            new Field('name', true, 'English name', 'Gulf Trading LLC'),
            new Field('name_local', false, 'Name in Chinese or Arabic'),
            new Field('country', false, 'Code or name from the Countries list', 'AE'),
            new Field('city', false, null, 'Dubai'),
            new Field('address', false),
            new Field('currency', false, 'Code from the Currencies list', 'USD'),
        ];
    }

    /** @return list<Field> */
    protected function trailingFields(): array
    {
        return [
            new Field('notes', false),
            new Field('active', false, 'yes or no; empty keeps it (new: yes)', 'yes'),
        ];
    }

    protected function commonRules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:200'],
            'name_local' => ['nullable', 'string', 'max:200'],
            'country' => ['nullable', 'string', 'max:80'],
            'city' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:2000'],
            'currency' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'active' => ['nullable', 'string', 'max:10'],
        ];
    }

    protected function mapCommon(string $key, string $value): ?array
    {
        return match ($key) {
            'name', 'name_local', 'city', 'address', 'notes' => [$key => $this->nullable($value)],
            'country' => ['country' => $this->lookupCode('country', $value, __('Country'))],
            'currency' => ['currency' => $this->lookupCode('currency', $value, __('Currency'))],
            'active' => ['active' => $this->flag($value)],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function commonExport(Party $party): array
    {
        return [
            'code' => $party->code, 'name' => $party->name, 'name_local' => $party->name_local,
            'country' => $party->country, 'city' => $party->city, 'address' => $party->address,
            'currency' => $party->currency, 'notes' => $party->notes, 'active' => $party->active ? 'yes' : 'no',
        ];
    }

    protected function scoped(Builder $query, User $user): Builder
    {
        return $query;
    }
}
