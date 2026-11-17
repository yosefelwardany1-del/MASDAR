<?php

namespace App\Services\Parties;

/**
 * The three kinds a party can be (spec §3.1), each with its own screen so each can be granted
 * separately (§6). The order of the cases is the order that decides a new party's code series:
 * a party that is a client and a supplier is numbered as a client.
 */
enum PartyKind: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Shipping = 'shipping';

    /** The boolean column on parties that marks this kind. */
    public function column(): string
    {
        return match ($this) {
            self::Client => 'is_client',
            self::Supplier => 'is_supplier',
            self::Shipping => 'is_shipping',
        };
    }

    /** The screen key in config/screens.php. */
    public function screen(): string
    {
        return match ($this) {
            self::Client => 'clients',
            self::Supplier => 'suppliers',
            self::Shipping => 'shipping_companies',
        };
    }

    /** The route name prefix and URI of the screen. */
    public function routePrefix(): string
    {
        return match ($this) {
            self::Client => 'clients',
            self::Supplier => 'suppliers',
            self::Shipping => 'shipping-companies',
        };
    }

    /** The NumberingService series of a party whose first kind this is. */
    public function series(): string
    {
        return match ($this) {
            self::Client => 'client',
            self::Supplier => 'supplier',
            self::Shipping => 'shipping_company',
        };
    }

    /** English, shown through __(). */
    public function label(): string
    {
        return match ($this) {
            self::Client => 'Client',
            self::Supplier => 'Supplier',
            self::Shipping => 'Shipping company',
        };
    }

    /** English, shown through __(). */
    public function plural(): string
    {
        return match ($this) {
            self::Client => 'Clients',
            self::Supplier => 'Suppliers',
            self::Shipping => 'Shipping companies',
        };
    }

    /** English, shown through __(). */
    public function newLabel(): string
    {
        return match ($this) {
            self::Client => 'New client',
            self::Supplier => 'New supplier',
            self::Shipping => 'New shipping company',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Client => 'bi-person-badge',
            self::Supplier => 'bi-shop',
            self::Shipping => 'bi-truck',
        };
    }

    /**
     * The first kind set in $flags (keyed by column), in case order; null when none is.
     *
     * @param  array<string, mixed>  $flags
     */
    public static function firstOf(array $flags): ?self
    {
        foreach (self::cases() as $kind) {
            if (! empty($flags[$kind->column()])) {
                return $kind;
            }
        }

        return null;
    }
}
