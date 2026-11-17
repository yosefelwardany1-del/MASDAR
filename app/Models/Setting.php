<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The company's settings — a single row (the table's CHECK keeps id = 1). Reading never
 * writes: before anybody saves, current() returns the defaults unsaved. Written only by
 * SettingsController.
 */
class Setting extends Model
{
    protected $fillable = ['company_name', 'company_name_ar'];

    /**
     * MASDAR's legal name as on its Proforma Invoice (spec S1). The Arabic name is OPEN (spec
     * Q-B1): until MASDAR gives one, the brand on the logo stands in, in Latin letters.
     */
    protected $attributes = [
        'company_name' => 'MASDAR FOR TRADING CO., LIMITED',
        'company_name_ar' => 'MASDAR TRADING',
    ];

    public static function current(): self
    {
        if ($row = static::query()->find(1)) {
            return $row;
        }

        $fresh = new static;
        $fresh->id = 1;

        return $fresh;
    }
}
