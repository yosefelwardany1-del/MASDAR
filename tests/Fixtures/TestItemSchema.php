<?php

namespace Tests\Fixtures;

use App\Support\IO\Field;
use App\Support\IO\ResourceSchema;
use Illuminate\Database\Eloquent\Model;

/** The import/export resource for TestItem; gated by the `settings` screen. */
class TestItemSchema extends ResourceSchema
{
    public function key(): string
    {
        return 'test-items';
    }

    public function label(): string
    {
        return 'Test items';
    }

    public function model(): string
    {
        return TestItem::class;
    }

    public function permissionModule(): string
    {
        return 'settings';
    }

    public function uniqueKey(): ?string
    {
        return 'sku';
    }

    public function fields(): array
    {
        return [
            new Field('sku', true, 'Unique code', 'BK-001'),
            new Field('name', true, null, 'A book'),
            new Field('pages', false, 'Whole number', '240'),
        ];
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:255'],
            'pages' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function toAttributes(array $row): array
    {
        if ($row['name'] === 'unresolvable') {
            throw new \RuntimeException("Name '{$row['name']}' not found.");
        }

        return ['sku' => $row['sku'], 'name' => $row['name'], 'pages' => $row['pages'] !== '' ? (int) $row['pages'] : null];
    }

    public function toExportRow(Model $model): array
    {
        return ['sku' => $model->sku, 'name' => $model->name, 'pages' => $model->pages];
    }
}
