<?php

/*
| The one screen registry. Each screen drives BOTH its sidebar entry and its permissions:
| `{screen}.{level}` for every level it declares. Levels are cumulative (edit includes view,
| approve includes edit) — see App\Security\Screens. A screen marked `signed_in` needs only a
| signed-in user and has no permissions (a user with no roles still lands somewhere).
|
| Each later sub-project adds its own screens here; RolesAndPermissionsSeeder creates their
| permissions on the next deploy and gives them to the roles whose defaults include them.
*/

return [
    'home' => [
        'label' => 'الرئيسية',
        'route' => 'dashboard',
        'group' => 'overview',
        'icon' => 'bi-house-door',
        'levels' => [],
        'signed_in' => true,
    ],
    'users' => [
        'label' => 'المستخدمون',
        'route' => 'users.index',
        'group' => 'admin',
        'icon' => 'bi-people',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'roles' => [
        'label' => 'الأدوار والصلاحيات',
        'route' => 'roles.index',
        'group' => 'admin',
        'icon' => 'bi-shield-lock',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'settings' => [
        'label' => 'إعدادات الشركة',
        'route' => 'settings.edit',
        'group' => 'admin',
        'icon' => 'bi-gear',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
    'audit_log' => [
        'label' => 'سجل التدقيق',
        'route' => 'audit-logs.index',
        'group' => 'admin',
        'icon' => 'bi-journal-text',
        'levels' => ['view'],
        'signed_in' => false,
    ],
    'import_export' => [
        'label' => 'الاستيراد والتصدير',
        'route' => 'import-export.index',
        'group' => 'admin',
        'icon' => 'bi-arrow-down-up',
        'levels' => ['view', 'edit'],
        'signed_in' => false,
    ],
];
