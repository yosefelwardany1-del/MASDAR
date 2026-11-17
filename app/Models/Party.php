<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
use App\Services\Parties\PartyKind;
use App\Support\Access\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client, supplier or shipping company — or more than one (spec §3.1). Never deleted once
 * anything refers to it: deactivated. Written only by App\Services\Parties\PartyService.
 */
class Party extends Model
{
    use HasAttachments, HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_client' => 'boolean', 'is_supplier' => 'boolean', 'is_shipping' => 'boolean',
            'active' => 'boolean', 'deposit_percent' => 'decimal:2',
        ];
    }

    /** Attachments follow the screen of the party's first kind. */
    public function attachmentModule(): string
    {
        return match (true) {
            $this->is_client => 'clients',
            $this->is_supplier => 'suppliers',
            default => 'shipping_companies',
        };
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->where('is_client', true);
    }

    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->where('is_supplier', true);
    }

    public function scopeShippingCompanies(Builder $query): Builder
    {
        return $query->where('is_shipping', true);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function interactions(): HasMany
    {
        return $this->hasMany(CrmInteraction::class)->orderByDesc('happened_at');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CrmTask::class);
    }

    public function accountManager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'account_manager_id');
    }

    public function proformaInvoices(): HasMany
    {
        return $this->hasMany(ProformaInvoice::class, 'client_id');
    }

    public function supplierOrders(): HasMany
    {
        return $this->hasMany(SupplierOrder::class, 'supplier_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'shipping_company_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'supplier_id');
    }

    public function isKind(PartyKind $kind): bool
    {
        return (bool) $this->{$kind->column()};
    }

    /** @return list<PartyKind> the kinds this party is, in numbering order */
    public function kinds(): array
    {
        return array_values(array_filter(PartyKind::cases(), fn (PartyKind $kind) => $this->isKind($kind)));
    }

    /**
     * Narrow a query on parties to those $user may open through at least one of their kinds'
     * screens: a client only as Visibility::scopeClients allows, a supplier or a shipping
     * company with its screen's view permission.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $kinds = array_filter(PartyKind::cases(), fn (PartyKind $kind) => $user->can($kind->screen().'.view'));
        if ($kinds === []) {
            return $query->whereRaw('false');
        }

        return $query->where(function (Builder $q) use ($kinds, $user) {
            foreach ($kinds as $kind) {
                $q->orWhere(fn (Builder $k) => $kind === PartyKind::Client
                    ? Visibility::scopeClients($k->where('is_client', true), $user)
                    : $k->where($kind->column(), true));
            }
        });
    }

    /** Whether $user may open this party through one of its kinds' screens. */
    /**
     * Attachments of a party: the user holds $level on the screen of one of its kinds, and may
     * open the party at all (a sales user limited to their own clients may not open another's).
     */
    public function attachmentAllows(User $user, string $level): bool
    {
        $level = $level === 'delete' ? 'edit' : $level;
        foreach ($this->kinds() as $kind) {
            if ($user->can($kind->screen().'.'.$level)) {
                return $this->isVisibleTo($user);
            }
        }

        return false;
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    /** The primary contact, else the first, from the loaded contacts. */
    public function primaryContact(): ?PartyContact
    {
        return $this->contacts->first();
    }
}
