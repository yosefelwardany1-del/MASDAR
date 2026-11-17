<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\User;
use App\Services\Media\ImageStore;
use App\Services\Numbering\NumberingService;
use App\Support\Audit\AuditTrail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of products (spec §3.2). The Products screen and the PI screen (a product
 * created from a PI line) both come here. Products are deactivated, never deleted.
 */
class ProductService
{
    public function __construct(private NumberingService $numbers, private ImageStore $images) {}

    /** @param array<string, mixed> $data validated product fields (no code: it is numbered here) */
    public function create(array $data, User $by, ?UploadedFile $photo = null): Product
    {
        return DB::transaction(function () use ($data, $by, $photo) {
            $product = Product::create(array_merge($data, [
                'code' => $this->numbers->next('product'),
                'created_by' => $by->id,
            ]));
            if ($photo) {
                $this->images->store($product, $photo, 'product-photos', 'photo');
            }
            AuditTrail::record('created', $product, [], $product->only(['code', 'name', 'unit', 'supplier_id']));

            return $product;
        });
    }

    /** @param array<string, mixed> $data validated product fields */
    public function update(Product $product, array $data, ?UploadedFile $photo = null): Product
    {
        return DB::transaction(function () use ($product, $data, $photo) {
            $old = $product->only(array_keys($data));
            $product->update($data);
            if ($photo) {
                $this->images->store($product, $photo, 'product-photos', 'photo');
                $data['photo_replaced'] = true;
            }
            AuditTrail::record('updated', $product, $old, $product->only(array_keys($old)) + array_intersect_key($data, ['photo_replaced' => 1]));

            return $product;
        });
    }

    public function removePhoto(Product $product): void
    {
        DB::transaction(function () use ($product) {
            $this->images->delete($product, 'photo');
            AuditTrail::record('updated', $product, ['photo' => 'set'], ['photo' => null]);
        });
    }

    /** CBM per carton: L × W × H in cm ÷ 1,000,000, rounded half up to 4 places, without floats. */
    public static function cbm(string $length, string $width, string $height): string
    {
        $exact = bcdiv(bcmul(bcmul($length, $width, 8), $height, 12), '1000000', 12);

        return bcadd($exact, '0.00005', 4);
    }

    /** A product on a PI line is never deleted: it is taken out of the catalog. */
    public function deactivate(Product $product): Product
    {
        return $this->setActive($product, false, 'deactivated');
    }

    public function reactivate(Product $product): Product
    {
        return $this->setActive($product, true, 'reactivated');
    }

    private function setActive(Product $product, bool $active, string $action): Product
    {
        if ($product->active === $active) {
            return $product;
        }

        return DB::transaction(function () use ($product, $active, $action) {
            $product->update(['active' => $active]);
            AuditTrail::record($action, $product, ['active' => ! $active], ['active' => $active]);

            return $product;
        });
    }
}
