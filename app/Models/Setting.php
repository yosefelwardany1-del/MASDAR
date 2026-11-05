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

    protected $attributes = [
        'company_name' => 'Company',
        'company_name_ar' => 'الشركة',
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
