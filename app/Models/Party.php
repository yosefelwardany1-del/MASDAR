<?php

namespace App\Models;

use App\Models\Concerns\HasAttachments;
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
}
