<?php

namespace App\Security;

/**
 * MASDAR's roles (spec §6, proposed and editable in the role editor). A user may hold several;
 * their permissions are the union. `manager` holds every screen; `staff` starts with nothing
 * and is given screens in the role editor. `system_admin` is the software vendor's support
 * staff only: users, roles, settings and the audit log — never business data, never an
 * approval. There is no bypass role.
 */
final class Roles
{
    public const MANAGER = 'manager';

    public const SALES = 'sales';

    public const MERCHANDISER = 'merchandiser';

    public const WAREHOUSE_QC = 'warehouse_qc';

    public const LOGISTICS = 'logistics';

    public const ACCOUNTANT = 'accountant';

    public const HR = 'hr';

    public const STAFF = 'staff';

    public const SYSTEM_ADMIN = 'system_admin';

    public const ALL = [
        self::MANAGER,
        self::SALES,
        self::MERCHANDISER,
        self::WAREHOUSE_QC,
        self::LOGISTICS,
        self::ACCOUNTANT,
        self::HR,
        self::STAFF,
        self::SYSTEM_ADMIN,
    ];

    /** English names; shown through __() so each language gets its own. */
    private const LABELS = [
        self::MANAGER => 'Manager',
        self::SALES => 'Sales',
        self::MERCHANDISER => 'Merchandiser (order follow-up)',
        self::WAREHOUSE_QC => 'Warehouse & inspection',
        self::LOGISTICS => 'Logistics',
        self::ACCOUNTANT => 'Accountant',
        self::HR => 'HR & administration',
        self::STAFF => 'Staff',
        self::SYSTEM_ADMIN => 'System administrator',
    ];

    /** The role's name in the user's language; an unknown (custom) role shows its own name. */
    public static function label(string $role): string
    {
        return isset(self::LABELS[$role]) ? __(self::LABELS[$role]) : $role;
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
        $grants = fn (array $levels) => array_merge(...array_map(
            fn ($screen, $level) => Screens::grantsFor($screen, $level),
            array_keys($levels),
            $levels,
        ));

        return match ($role) {
            self::MANAGER => Screens::allPermissions(),
            self::SALES => $grants([
                'clients' => 'edit', 'crm' => 'edit', 'products' => 'edit', 'quotations' => 'edit',
                'proforma_invoices' => 'edit', 'supplier_orders' => 'view', 'containers' => 'view',
                'payments' => 'view', 'claims' => 'edit', 'analytics' => 'view',
            ]),
            self::MERCHANDISER => $grants([
                'clients' => 'view', 'suppliers' => 'edit', 'shipping_companies' => 'view', 'crm' => 'edit',
                'products' => 'edit', 'quotations' => 'view', 'proforma_invoices' => 'view', 'supplier_orders' => 'edit',
                'goods_receipts' => 'view', 'containers' => 'view', 'claims' => 'edit', 'sourcing' => 'edit',
            ]),
            self::WAREHOUSE_QC => $grants([
                'suppliers' => 'view', 'products' => 'view', 'supplier_orders' => 'view',
                'goods_receipts' => 'edit', 'containers' => 'edit', 'claims' => 'edit',
            ]),
            self::LOGISTICS => $grants([
                'clients' => 'view', 'shipping_companies' => 'edit', 'crm' => 'edit', 'products' => 'view',
                'proforma_invoices' => 'view', 'supplier_orders' => 'view', 'goods_receipts' => 'view',
                'containers' => 'edit',
            ]),
            self::ACCOUNTANT => $grants([
                'clients' => 'view', 'suppliers' => 'view', 'shipping_companies' => 'view', 'products' => 'view',
                'proforma_invoices' => 'view', 'supplier_orders' => 'view', 'goods_receipts' => 'view',
                'containers' => 'view', 'payments' => 'edit', 'accounting' => 'edit', 'finance_reports' => 'view',
                'payroll' => 'view', 'claims' => 'view', 'profit' => 'view', 'analytics' => 'view',
            ]),
            self::HR => $grants([
                'employees' => 'edit', 'leave' => 'edit', 'payroll' => 'edit',
            ]),
            self::SYSTEM_ADMIN => $grants([
                'users' => 'edit', 'roles' => 'edit', 'settings' => 'edit', 'audit_log' => 'view',
            ]),
            default => [],
        };
    }
}
