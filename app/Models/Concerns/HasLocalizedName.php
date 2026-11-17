<?php

namespace App\Models\Concerns;

/**
 * Records with a name per language (name, name_ar, name_zh): displayName() gives the one for
 * the current locale, falling back to the English `name`.
 */
trait HasLocalizedName
{
    public function displayName(): string
    {
        $localized = match (app()->getLocale()) {
            'ar' => $this->name_ar,
            'zh_CN' => $this->name_zh,
            default => null,
        };

        return filled($localized) ? $localized : (string) $this->name;
    }
}
