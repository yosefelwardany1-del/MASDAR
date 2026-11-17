<?php

namespace App\Support\IO\Resources;

use App\Models\Party;
use App\Models\User;
use App\Services\Parties\PartyKind;
use App\Support\Access\Visibility;
use App\Support\IO\Field;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Clients for the Import & export screen (gated by the clients screen). */
class ClientSchema extends PartySchema
{
    protected function kind(): PartyKind
    {
        return PartyKind::Client;
    }

    public function key(): string
    {
        return 'clients';
    }

    public function fields(): array
    {
        return [
            ...$this->commonFields(),
            new Field('account_manager_email', false, 'E-mail of a user'),
            new Field('source', false, 'Code or name from "How clients find us"', 'referral'),
            new Field('stage', false, 'Code or name from "Client stages"', 'lead'),
            new Field('deposit_percent', false, '0–100; empty uses the business default', '30'),
            new Field('default_destination', false, 'Shipped to', 'Jebel Ali, Dubai'),
            ...$this->trailingFields(),
        ];
    }

    public function rules(): array
    {
        return $this->commonRules() + [
            'account_manager_email' => ['nullable', 'email'],
            'source' => ['nullable', 'string', 'max:80'],
            'stage' => ['nullable', 'string', 'max:80'],
            'deposit_percent' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'default_destination' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function mapColumn(string $key, string $value): array
    {
        return $this->mapCommon($key, $value) ?? match ($key) {
            'account_manager_email' => ['account_manager_id' => $value === '' ? null : $this->requireUserByEmail($value, __('Account manager'))],
            'source' => ['source' => $this->lookupCode('party_source', $value, __('How they found us'))],
            'stage' => ['stage' => $this->lookupCode('client_stage', $value, __('Stage'))],
            'deposit_percent', 'default_destination' => [$key => $this->nullable($value)],
            default => [],
        };
    }

    public function writeRow(array $attrs, ?Model $existing, User $user): Model
    {
        // As on the screen: a sales user who sees only their own clients manages what they write.
        if (Visibility::ownClientsOnly($user)) {
            $attrs['account_manager_id'] = $user->id;
        }

        return parent::writeRow($attrs, $existing, $user);
    }

    public function toExportRow(Model $model): array
    {
        /** @var Party $model */
        return $this->commonExport($model) + [
            'account_manager_email' => $model->accountManager?->email,
            'source' => $model->source,
            'stage' => $model->stage,
            'deposit_percent' => $model->deposit_percent,
            'default_destination' => $model->default_destination,
        ];
    }

    protected function scoped(Builder $query, User $user): Builder
    {
        return Visibility::scopeClients($query, $user);
    }
}
