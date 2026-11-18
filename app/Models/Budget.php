<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A budget for a year: an amount per income or expense account and month (spec §5.8). Written by App\Services\Accounting\BudgetService. */
class Budget extends Model
{
    protected $guarded = ['id'];

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
