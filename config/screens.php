<?php

/*
| The one screen registry. Each screen drives BOTH its sidebar entry and its permissions:
| `{screen}.{level}` for every level it declares. Levels are cumulative (edit includes view,
| approve includes edit) — see App\Security\Screens. A screen marked `signed_in` needs only a
| signed-in user and has no permissions (a user with no roles still lands somewhere).
|
| Labels are English and shown through __(), so each user sees them in their own language.
| RolesAndPermissionsSeeder creates new permissions on the next deploy and gives them to the
| roles whose defaults include them.
*/

return [
    'home' => [
        'label' => 'Home',
        'route' => 'dashboard',
        'group' => 'overview',
        'icon' => 'bi-house-door',
        'levels' => [],
        'signed_in' => true,
    ],

    // CRM: the three interaction points (spec §5.1) and the follow-up log.
    // The CRM itself: its home, the pipeline, every contact, the follow-up tasks.
    'crm' => [
        'label' => 'CRM',
        'route' => 'crm.index',
        'group' => 'crm',
        'icon' => 'bi-bullseye',
        'levels' => ['view', 'edit'],
        'active' => ['crm.*'],
        'signed_in' => false,
    ],
    'clients' => [
        'label' => 'Clients',
        'route' => 'clients.index',
        'group' => 'crm',
        'icon' => 'bi-person-badge',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'suppliers' => [
        'label' => 'Suppliers',
        'route' => 'suppliers.index',
        'group' => 'crm',
        'icon' => 'bi-shop',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'shipping_companies' => [
        'label' => 'Shipping companies',
        'route' => 'shipping-companies.index',
        'group' => 'crm',
        'icon' => 'bi-truck',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],

    // Sales: products and the Proforma Invoice (spec §5.2).
    'products' => [
        'label' => 'Products',
        'route' => 'products.index',
        'group' => 'sales',
        'icon' => 'bi-box-seam',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'quotations' => [
        'label' => 'Quotations',
        'route' => 'quotations.index',
        'group' => 'sales',
        'icon' => 'bi-chat-square-quote',
        'levels' => ['view', 'edit'],
        'active' => ['quotations.*'],
        'signed_in' => false,
    ],
    'proforma_invoices' => [
        'label' => 'Sales orders (PI)',
        'route' => 'proforma-invoices.index',
        'group' => 'sales',
        'icon' => 'bi-receipt',
        'levels' => ['view', 'edit', 'approve'],
        'signed_in' => false,
    ],

    // Factory quotes compared before quoting the client (spec §5.8); names factories' prices.
    'sourcing' => [
        'label' => 'Factory quotes',
        'route' => 'sourcing.index',
        'group' => 'sales',
        'icon' => 'bi-ui-checks-grid',
        'levels' => ['view', 'edit'],
        'active' => ['sourcing.*'],
        'signed_in' => false,
    ],
    // Sales and purchase analysis (spec §5.8).
    'analytics' => [
        'label' => 'Analysis',
        'route' => 'analytics.index',
        'group' => 'overview',
        'icon' => 'bi-bar-chart-line',
        'levels' => ['view'],
        'active' => ['analytics.*'],
        'signed_in' => false,
    ],

    // Operations: the two follow-ups (spec §5.3–§5.5).
    'supplier_orders' => [
        'label' => 'Purchase orders',
        'route' => 'supplier-orders.index',
        'group' => 'operations',
        'icon' => 'bi-kanban',
        'levels' => ['view', 'edit'],
        'active' => ['supplier-orders.*'],
        'signed_in' => false,
    ],
    'goods_receipts' => [
        'label' => 'Goods receipt & inspection',
        'route' => 'goods-receipts.index',
        'group' => 'operations',
        'icon' => 'bi-clipboard-check',
        'levels' => ['view', 'edit'],
        'active' => ['goods-receipts.*'],
        'signed_in' => false,
    ],
    'containers' => [
        'label' => 'Container follow-up',
        'route' => 'containers.index',
        'group' => 'operations',
        'icon' => 'bi-boxes',
        'levels' => ['view', 'edit'],
        'active' => ['containers.*', 'shipments.*'],
        'signed_in' => false,
    ],

    // Quality claims with factories and clients, settled by credit and debit notes (spec §5.8).
    'claims' => [
        'label' => 'Claims & notes',
        'route' => 'claims.index',
        'group' => 'operations',
        'icon' => 'bi-exclamation-octagon',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['claims.*', 'trade-notes.*'],
        'signed_in' => false,
    ],

    // Finance (spec §5.6).
    'payments' => [
        'label' => 'Payments',
        'route' => 'payments.index',
        'group' => 'finance',
        'icon' => 'bi-cash-coin',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['payments.*', 'money-accounts.*', 'fx-rates.*'],
        'signed_in' => false,
    ],
    'accounting' => [
        'label' => 'Accounting',
        'route' => 'accounting.index',
        'group' => 'finance',
        'icon' => 'bi-journal-bookmark',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['accounting.*'],
        'signed_in' => false,
    ],
    'finance_reports' => [
        'label' => 'Financial reports',
        'route' => 'finance-reports.index',
        'group' => 'finance',
        'icon' => 'bi-graph-up',
        'levels' => ['view'],
        'active' => ['finance-reports.*'],
        'signed_in' => false,
    ],

    // Batch C (spec §5.8): what complete books need.
    'bank_reconciliation' => [
        'label' => 'Bank reconciliation',
        'route' => 'bank-reconciliation.index',
        'group' => 'finance',
        'icon' => 'bi-bank2',
        'levels' => ['view', 'edit'],
        'active' => ['bank-reconciliation.*'],
        'signed_in' => false,
    ],
    'expense_claims' => [
        'label' => 'Expense claims',
        'route' => 'expense-claims.index',
        'group' => 'finance',
        'icon' => 'bi-receipt-cutoff',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['expense-claims.*'],
        'signed_in' => false,
    ],
    'budgets' => [
        'label' => 'Budgets',
        'route' => 'budgets.index',
        'group' => 'finance',
        'icon' => 'bi-clipboard-data',
        'levels' => ['view', 'edit'],
        'active' => ['budgets.*'],
        'signed_in' => false,
    ],
    'fixed_assets' => [
        'label' => 'Fixed assets',
        'route' => 'fixed-assets.index',
        'group' => 'finance',
        'icon' => 'bi-pc-display',
        'levels' => ['view', 'edit'],
        'active' => ['fixed-assets.*'],
        'signed_in' => false,
    ],

    // Profit per sales order and per container (spec §5.8); names factories' prices.
    'profit' => [
        'label' => 'Profit',
        'route' => 'profit.index',
        'group' => 'finance',
        'icon' => 'bi-graph-up-arrow',
        'levels' => ['view'],
        'active' => ['profit.*'],
        'signed_in' => false,
    ],

    // HR (spec §5.7): the employee file, leave and attendance, payroll.
    'employees' => [
        'label' => 'Employees',
        'route' => 'employees.index',
        'group' => 'hr',
        'icon' => 'bi-person-vcard',
        'levels' => ['view', 'edit'],
        'active' => ['employees.*'],
        'signed_in' => false,
    ],
    'leave' => [
        'label' => 'Leave & attendance',
        'route' => 'leave.index',
        'group' => 'hr',
        'icon' => 'bi-calendar-check',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['leave.*', 'attendance.*'],
        'signed_in' => false,
    ],
    'payroll' => [
        'label' => 'Payroll',
        'route' => 'payroll.index',
        'group' => 'hr',
        'icon' => 'bi-wallet2',
        'levels' => ['view', 'edit', 'approve'],
        'active' => ['payroll.*'],
        'signed_in' => false,
    ],

    // Administration.
    'configuration' => [
        'label' => 'Lists & options',
        'route' => 'configuration.index',
        'group' => 'admin',
        'icon' => 'bi-sliders',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'users' => [
        'label' => 'Users',
        'route' => 'users.index',
        'group' => 'admin',
        'icon' => 'bi-people',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'roles' => [
        'label' => 'Roles & permissions',
        'route' => 'roles.index',
        'group' => 'admin',
        'icon' => 'bi-shield-lock',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'settings' => [
        'label' => 'Company settings',
        'route' => 'settings.edit',
        'group' => 'admin',
        'icon' => 'bi-gear',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'audit_log' => [
        'label' => 'Audit log',
        'route' => 'audit-logs.index',
        'group' => 'admin',
        'icon' => 'bi-journal-text',
        'levels' => ['view'],
        'signed_in' => false,
    ],
    'import_export' => [
        'label' => 'Import & export',
        'route' => 'import-export.index',
        'group' => 'admin',
        'icon' => 'bi-arrow-down-up',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
];
