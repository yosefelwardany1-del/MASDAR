<?php

namespace App\Services\Configuration;

use App\Models\BusinessSetting;
use App\Models\JournalLine;
use App\Support\Audit\AuditTrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The only writer of business_settings (one row). */
class BusinessSettingsService
{
    /** @param array<string, mixed> $data validated */
    public function update(array $data): BusinessSetting
    {
        return DB::transaction(function () use ($data) {
            $setting = BusinessSetting::current();
            if (isset($data['book_currency']) && $data['book_currency'] !== $setting->book_currency && JournalLine::query()->exists()) {
                throw ValidationException::withMessages(['book_currency' => __('The book currency cannot change once the ledger has entries.')]);
            }
            $old = $setting->only(array_keys($data));
            $setting->fill($data)->save();
            AuditTrail::record('updated', $setting, $old, $setting->only(array_keys($data)));

            return $setting;
        });
    }
}
