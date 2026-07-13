<?php

namespace App\Support;

/**
 * Canonical navigation catalog. Visibility is gated by permissions;
 * tenant admins may hide items per role (presentation only).
 */
class MenuCatalog
{
    /** @return list<array{key: string, label: string, href: string, permission: string|null, group: string}> */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/dashboard', 'permission' => null, 'group' => 'Core'],
            ['key' => 'projects', 'label' => 'Projects', 'href' => '/projects', 'permission' => 'projects:read', 'group' => 'Operations'],
            ['key' => 'requisitions', 'label' => 'Requisitions', 'href' => '/requisitions', 'permission' => 'requisitions:read', 'group' => 'Operations'],
            ['key' => 'finance', 'label' => 'Finance', 'href' => '/finance', 'permission' => 'budgets:read', 'group' => 'Finance'],
            ['key' => 'procurement', 'label' => 'Procurement', 'href' => '/procurement', 'permission' => 'procurement:read', 'group' => 'Supply Chain'],
            ['key' => 'inventory', 'label' => 'Inventory', 'href' => '/inventory', 'permission' => 'inventory:read', 'group' => 'Supply Chain'],
            ['key' => 'payroll', 'label' => 'Payroll', 'href' => '/payroll', 'permission' => 'payroll:read', 'group' => 'HR'],
            ['key' => 'equipment', 'label' => 'Equipment', 'href' => '/equipment', 'permission' => 'equipment:read', 'group' => 'HR'],
            ['key' => 'reports', 'label' => 'Reports', 'href' => '/reports', 'permission' => 'reports:read', 'group' => 'Insights'],
            ['key' => 'audit', 'label' => 'Audit', 'href' => '/audit', 'permission' => 'audit:read', 'group' => 'Insights'],
            ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin/users', 'permission' => 'auth:read', 'group' => 'Administration'],
        ];
    }

    /** @return list<string> */
    public static function hrefs(): array
    {
        return array_column(self::items(), 'href');
    }

    /** @return list<string> */
    public static function tenantRoles(): array
    {
        return array_keys(array_filter(
            ModulePermission::matrix(),
            fn (string $role) => $role !== 'Platform Admin',
            ARRAY_FILTER_USE_KEY,
        ));
    }

    /** @return list<string> */
    public static function assignableRoles(): array
    {
        return array_values(array_filter(
            self::tenantRoles(),
            fn (string $role) => $role !== 'Platform Admin',
        ));
    }

    /** @return list<string> */
    public static function editablePermissionRoles(): array
    {
        return array_values(array_filter(
            self::tenantRoles(),
            fn (string $role) => ! in_array($role, ['Platform Admin'], true),
        ));
    }
}
