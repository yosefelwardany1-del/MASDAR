<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A line of a service invoice; amount = quantity × unit price (generated). */
class ServiceInvoiceLine extends Model
{
    protected $guarded = ['id', 'amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'amount' => 'decimal:4'];
    }
}
