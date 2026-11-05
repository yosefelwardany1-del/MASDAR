<?php

namespace App\Security;

/**
 * The starter's roles. A user may hold several; their permissions are the union. `manager`
 * holds every screen; `supervisor` and `staff` start with nothing and are given their screens
 * per client (the role editor, or defaultGrants below); each client adds the roles its own
 * specification names. `system_admin` is the software vendor's support staff only: users,
 * roles, settings and the audit log — never business data, never an approval. There is no
 * bypass role.
 */
final class Roles
{
    public const MANAGER = 'manager';

    public const SUPERVISOR = 'supervisor';

    public const STAFF = 'staff';

    public const SYSTEM_ADMIN = 'system_admin';

    public const ALL = [
        self::MANAGER,
        self::SUPERVISOR,
        self::STAFF,
        self::SYSTEM_ADMIN,
    ];

    private const LABELS = [
        self::MANAGER => 'مدير',
        self::SUPERVISOR => 'مشرف',
        self::STAFF => 'موظف',
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
