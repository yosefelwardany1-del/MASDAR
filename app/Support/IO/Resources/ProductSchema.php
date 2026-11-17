<?php

namespace App\Support\IO\Resources;

use App\Models\Party;
use App\Models\Product;
use App\Models\User;
use App\Services\Products\ProductService;
use App\Support\Access\Visibility;
use App\Support\IO\Field;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Products for the Import & export screen (gated by the products screen), written through
 * ProductService. The supplier columns are exported and imported only for a user who may see
 * supplier prices; for anyone else they come out empty and are ignored on the way in.
 * Photos are not imported: they are uploaded on the product's page.
 */
class ProductSchema extends ServiceBackedSchema
{
    private const AMOUNTS = ['qty_per_carton', 'carton_length_cm', 'carton_width_cm', 'carton_height_cm', 'cbm_per_carton', 'kg_per_carton', 'supplier_price', 'sale_price'];

    private const SUPPLIER_COLUMNS = ['supplier_code', 'supplier_price', 'supplier_currency'];

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return __('Products');
    }

    public function model(): string
    {
        return Product::class;
    }

    public function permissionModule(): string
    {
        return 'products';
    }

    public function exportWith(): array
    {
        return ['supplier'];
    }

    public function fields(): array
    {
        return [
            new Field('code', false, 'Empty to add a new one; an existing code to update it'),
            new Field('name', true, 'English name', 'Blue ceramic cup'),
            new Field('name_local', false, 'Name in Chinese or Arabic', '蓝色陶瓷杯'),
            new Field('description', false),
            new Field('unit', false, 'Code or name from the Units list (new: pcs)', 'pcs'),
            new Field('qty_per_carton', false, 'Number greater than 0', '48'),
            new Field('carton_length_cm', false, 'cm', '60'),
            new Field('carton_width_cm', false, 'cm', '40'),
            new Field('carton_height_cm', false, 'cm', '35'),
            new Field('cbm_per_carton', false, 'm³; empty is worked out from the carton size', '0.084'),
            new Field('kg_per_carton', false, 'Gross KG', '12.5'),
            new Field('supplier_code', false, 'Code of a supplier, e.g. S-0001'),
            new Field('supplier_price', false, 'Number', '7.20'),
            new Field('supplier_currency', false, 'Code from the Currencies list', 'CNY'),
            new Field('sale_price', false, 'Number', '1.85'),
            new Field('sale_currency', false, 'Code from the Currencies list', 'USD'),
            new Field('hs_code', false, null, '6912.00'),
            new Field('active', false, 'yes or no; empty keeps it (new: yes)', 'yes'),
        ];
    }

    public function rules(): array
    {
        $amount = ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999999'];

        return [
            'code' => ['nullable', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:255'],
            'name_local' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit' => ['nullable', 'string', 'max:80'],
            'qty_per_carton' => ['nullable', 'numeric', 'decimal:0,4', 'gt:0', 'max:99999999999999'],
            'carton_length_cm' => $amount,
            'carton_width_cm' => $amount,
            'carton_height_cm' => $amount,
            'cbm_per_carton' => $amount,
            'kg_per_carton' => $amount,
            'supplier_code' => ['nullable', 'string', 'max:30'],
            'supplier_price' => $amount,
            'supplier_currency' => ['nullable', 'string', 'max:80'],
            'sale_price' => $amount,
            'sale_currency' => ['nullable', 'string', 'max:80'],
            'hs_code' => ['nullable', 'string', 'max:30'],
            'active' => ['nullable', 'string', 'max:10'],
        ];
    }

    public function findForImport(string $code, User $user): ?Model
    {
        return Product::where('code', $code)->first();
    }

    public function toAttributes(array $row): array
    {
        if (! $this->maySeeSupplier()) {
            $row = array_diff_key($row, array_flip(self::SUPPLIER_COLUMNS));
        }
        $attrs = parent::toAttributes($row);

        if (($attrs['cbm_per_carton'] ?? null) === null && isset($attrs['carton_length_cm'], $attrs['carton_width_cm'], $attrs['carton_height_cm'])) {
            $attrs['cbm_per_carton'] = ProductService::cbm($attrs['carton_length_cm'], $attrs['carton_width_cm'], $attrs['carton_height_cm']);
        }

        return $attrs;
    }

    protected function mapColumn(string $key, string $value): array
    {
        return match (true) {
            in_array($key, self::AMOUNTS, true), in_array($key, ['name', 'name_local', 'description', 'hs_code'], true) => [$key => $this->nullable($value)],
            $key === 'unit' => $value === '' ? [] : ['unit' => $this->lookupCode('unit', $value, __('Unit'))],
            $key === 'supplier_currency', $key === 'sale_currency' => [$key => $this->lookupCode('currency', $value, __('Currency'))],
            $key === 'supplier_code' => ['supplier_id' => $value === '' ? null : $this->supplierId($value)],
            $key === 'active' => ['active' => $this->flag($value)],
            default => [],
        };
    }

    public function writeRow(array $attrs, ?Model $existing, User $user): Model
    {
        $service = app(ProductService::class);
        if (array_key_exists('active', $attrs) && $attrs['active'] === null) {
            unset($attrs['active']);
        }

        return $existing ? $service->update($existing, $attrs) : $service->create($attrs, $user);
    }

    public function toExportRow(Model $model): array
    {
        /** @var Product $model */
        $supplier = $this->maySeeSupplier();

        return [
            'code' => $model->code, 'name' => $model->name, 'name_local' => $model->name_local,
            'description' => $model->description, 'unit' => $model->unit,
            'qty_per_carton' => $model->qty_per_carton, 'carton_length_cm' => $model->carton_length_cm,
            'carton_width_cm' => $model->carton_width_cm, 'carton_height_cm' => $model->carton_height_cm,
            'cbm_per_carton' => $model->cbm_per_carton, 'kg_per_carton' => $model->kg_per_carton,
            'supplier_code' => $supplier ? $model->supplier?->code : null,
            'supplier_price' => $supplier ? $model->supplier_price : null,
            'supplier_currency' => $supplier ? $model->supplier_currency : null,
            'sale_price' => $model->sale_price, 'sale_currency' => $model->sale_currency,
            'hs_code' => $model->hs_code, 'active' => $model->active ? 'yes' : 'no',
        ];
    }

    private function supplierId(string $code): int
    {
        return (int) (Party::where('is_supplier', true)->where('code', $code)->value('id')
            ?? throw new RuntimeException(__('Supplier :code not found.', ['code' => $code])));
    }

    private function maySeeSupplier(): bool
    {
        $user = auth()->user();

        return $user !== null && Visibility::canSeeSupplierPrices($user);
    }
}
