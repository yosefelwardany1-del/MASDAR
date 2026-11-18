<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A line of a quotation (spec §5.2): changes only while its quotation is a draft (trigger). Written only by App\Services\Quotations\QuotationService. */
class QuotationLine extends Model
{
    protected $guarded = ['id'];

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    /** Price × quantity, when a quantity is proposed. */
    public function amount(): ?string
    {
        if ($this->cartons === null) {
            return null;
        }

        return bcmul(bcmul((string) $this->cartons, (string) $this->qty_per_carton, 4), (string) $this->unit_price, 4);
    }
}
