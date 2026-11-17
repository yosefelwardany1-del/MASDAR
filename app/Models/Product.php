<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A catalog item with its photo and carton spec (spec §3.2). Written only by
 * App\Services\Products\ProductService.
 */
class Product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty_per_carton' => 'decimal:4', 'cbm_per_carton' => 'decimal:4', 'kg_per_carton' => 'decimal:4',
            'carton_length_cm' => 'decimal:4', 'carton_width_cm' => 'decimal:4', 'carton_height_cm' => 'decimal:4',
            'supplier_price' => 'decimal:4', 'sale_price' => 'decimal:4', 'active' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }
}
