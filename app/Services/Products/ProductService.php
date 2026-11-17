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
 * created from a PI line) both come here.
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
}
