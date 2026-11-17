<?php

namespace App\Models;

use App\Models\Concerns\HasLocalizedName;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An entry of an editable list (spec §3.4): units, container types, ports, currencies, container
 * statuses … — every OPEN choice the spec leaves to MASDAR is a list here, changed on the
 * Lists & options screen. Written only by App\Services\Configuration\LookupService.
 */
class Lookup extends Model
{
    use HasLocalizedName;

    public const LISTS = [
        'unit' => 'Units',
        'currency' => 'Currencies',
        'container_type' => 'Container types',
        'container_status' => 'Container statuses',
        'port' => 'Ports',
        'shipping_mode' => 'Shipping modes',
        'shipping_company_kind' => 'Kinds of shipping company',
        'party_source' => 'How clients find us',
        'client_stage' => 'Client stages',
        'interaction_channel' => 'Contact channels',
        'country' => 'Countries',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'active' => 'boolean', 'sort' => 'integer'];
    }

    public function scopeOfList(Builder $query, string $list): Builder
    {
        return $query->where('list', $list)->orderBy('sort')->orderBy('name');
    }

    /** Active entries of a list, as code => name in the current language. */
    public static function options(string $list): array
    {
        return static::query()->ofList($list)->where('active', true)->get()
            ->mapWithKeys(fn (self $l) => [$l->code => $l->displayName()])->all();
    }

    /** The name of one code in the current language; an unknown code shows itself. */
    public static function label(string $list, ?string $code): string
    {
        if ($code === null || $code === '') {
            return '';
        }
        $row = static::query()->where('list', $list)->where('code', $code)->first();

        return $row ? $row->displayName() : $code;
    }
}
