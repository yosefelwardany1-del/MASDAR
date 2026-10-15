<?php

namespace App\Security;

/**
 * The ten roles (spec §10.2). A user may hold several; their permissions are the union.
 * `system_admin` is the software vendor's support staff only: users, roles, settings and the audit log —
 * never business data, never an approval. There is no bypass role.
 */
final class Roles
{
    public const MANAGER = 'manager';

    public const ACCOUNTANT = 'accountant';

    public const SALES_OFFICER = 'sales_officer';

    public const COLLECTIONS_OFFICER = 'collections_officer';

    public const SHIPPING_OFFICER = 'shipping_officer';

    public const STOREKEEPER = 'storekeeper';

    public const PURCHASING_OFFICER = 'purchasing_officer';

    public const PRODUCTION_OFFICER = 'production_officer';

    public const EXHIBITIONS_OFFICER = 'exhibitions_officer';

    public const SYSTEM_ADMIN = 'system_admin';

    public const ALL = [
        self::MANAGER,
        self::ACCOUNTANT,
        self::SALES_OFFICER,
        self::COLLECTIONS_OFFICER,
        self::SHIPPING_OFFICER,
        self::STOREKEEPER,
        self::PURCHASING_OFFICER,
        self::PRODUCTION_OFFICER,
        self::EXHIBITIONS_OFFICER,
        self::SYSTEM_ADMIN,
    ];

    private const LABELS = [
        self::MANAGER => 'مدير',
        self::ACCOUNTANT => 'محاسب',
        self::SALES_OFFICER => 'مسؤول مبيعات',
        self::COLLECTIONS_OFFICER => 'مسؤول تحصيل',
        self::SHIPPING_OFFICER => 'مسؤول شحن',
        self::STOREKEEPER => 'أمين مخزن',
        self::PURCHASING_OFFICER => 'مسؤول مشتريات',
        self::PRODUCTION_OFFICER => 'مسؤول إنتاج',
        self::EXHIBITIONS_OFFICER => 'مسؤول معارض',
        self::SYSTEM_ADMIN => 'مدير النظام',
    ];

    /** Arabic name of a role; an unknown (custom) role shows its own name. */
    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }

    /**
     * The permissions a role starts with. Used only when the seeder creates the role, or
     * creates a permission for the first time — never to re-grant something an
     * administrator revoked in the role editor.
     *
     * @return list<string>
     */
    public static function defaultGrants(string $role): array
    {
        return match ($role) {
            self::MANAGER => Screens::allPermissions(),
            self::SYSTEM_ADMIN => array_merge(
                Screens::grantsFor('users', 'edit'),
                Screens::grantsFor('roles', 'edit'),
                Screens::grantsFor('settings', 'edit'),
                Screens::grantsFor('audit_log', 'view'),
            ),
            default => [],
        };
    }
}
