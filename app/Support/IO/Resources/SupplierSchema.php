<?php

namespace App\Support\IO\Resources;

use App\Models\Party;
use App\Services\Parties\PartyKind;
use App\Support\Access\Visibility;
use App\Support\IO\Field;
use Illuminate\Database\Eloquent\Model;

/**
 * Suppliers for the Import & export screen (gated by the suppliers screen). Bank details are
 * exported and imported only for a user who may see supplier prices, as on the screen.
 */
class SupplierSchema extends PartySchema
{
    protected function kind(): PartyKind
    {
        return PartyKind::Supplier;
    }

    public function key(): string
    {
        return 'suppliers';
    }

    public function fields(): array
    {
        return [
            ...$this->commonFields(),
            new Field('factory_address', false, 'Factory or market booth address', 'District 2, Booth 12345'),
            new Field('products_note', false, 'What they make', 'Ceramic cups'),
            new Field('bank_details', false, 'Bank or payment details'),
            ...$this->trailingFields(),
        ];
    }

    public function rules(): array
    {
        return $this->commonRules() + [
            'factory_address' => ['nullable', 'string', 'max:2000'],
            'products_note' => ['nullable', 'string', 'max:2000'],
            'bank_details' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function mapColumn(string $key, string $value): array
    {
        return $this->mapCommon($key, $value) ?? match ($key) {
            'factory_address', 'products_note' => [$key => $this->nullable($value)],
            'bank_details' => $this->mayTouchBank() ? ['bank_details' => $this->nullable($value)] : [],
            default => [],
        };
    }

    public function toExportRow(Model $model): array
    {
        /** @var Party $model */
        return $this->commonExport($model) + [
            'factory_address' => $model->factory_address,
            'products_note' => $model->products_note,
            'bank_details' => $this->mayTouchBank() ? $model->bank_details : null,
        ];
    }

    private function mayTouchBank(): bool
    {
        $user = auth()->user();

        return $user !== null && Visibility::canSeeSupplierPrices($user);
    }
}
