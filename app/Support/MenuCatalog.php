<?php

namespace App\Support;

/**
 * Canonical navigation catalog. Visibility is gated by permissions;
 * tenant admins may hide items per role (presentation only).
 *
 * Parents with children use href as the default destination (first sub-feature).
 * active_path is used for section highlighting and legacy menu-hide overrides.
 */
class MenuCatalog
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     href: string,
     *     permission: string|null,
     *     group: string,
     *     active_path?: string,
     *     children?: list<array{key: string, label: string, href: string, permission: string|null}>
     * }>
     */
    public static function items(): array
    {
        return [
            ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '/dashboard', 'permission' => null, 'group' => 'Core'],
            [
                'key' => 'projects',
                'label' => 'Projects',
                'href' => '/projects',
                'active_path' => '/projects',
                'permission' => 'projects:read',
                'group' => 'Operations',
                'children' => [
                    ['key' => 'projects.all', 'label' => 'All Projects', 'href' => '/projects', 'permission' => null],
                    ['key' => 'projects.compliance_rules', 'label' => 'Compliance Rules', 'href' => '/projects/compliance-rules', 'permission' => null],
                ],
            ],
            [
                'key' => 'requisitions',
                'label' => 'Requisitions',
                'href' => '/requisitions',
                'active_path' => '/requisitions',
                'permission' => 'requisitions:read',
                'group' => 'Operations',
                'children' => [
                    ['key' => 'requisitions.new', 'label' => 'New Requisition', 'href' => '/requisitions/create', 'permission' => 'requisitions:create'],
                    ['key' => 'requisitions.list', 'label' => 'Requisition List', 'href' => '/requisitions', 'permission' => null],
                    ['key' => 'requisitions.categories', 'label' => 'Categories', 'href' => '/requisitions/categories', 'permission' => null],
                    ['key' => 'requisitions.departments', 'label' => 'Departments', 'href' => '/requisitions/departments', 'permission' => null],
                    ['key' => 'requisitions.review_queue', 'label' => 'Review Queue', 'href' => '/requisitions/review-queue', 'permission' => 'requisitions:approve'],
                    ['key' => 'requisitions.fulfill_queue', 'label' => 'Fulfill Queue', 'href' => '/requisitions/fulfill-queue', 'permission' => 'requisitions:fulfill'],
                    ['key' => 'requisitions.fulfilled', 'label' => 'Fulfilled List', 'href' => '/requisitions/fulfilled', 'permission' => null],
                ],
            ],
            [
                'key' => 'finance',
                'label' => 'Finance',
                'href' => '/finance/overview',
                'active_path' => '/finance',
                'permission' => 'budgets:read',
                'group' => 'Finance',
                'children' => [
                    ['key' => 'finance.overview', 'label' => 'Finance Overview', 'href' => '/finance/overview', 'permission' => null],
                    ['key' => 'finance.approvals', 'label' => 'Fund Approvals', 'href' => '/finance/approvals', 'permission' => null],
                    ['key' => 'finance.organization_cash', 'label' => 'Organization Cash', 'href' => '/finance/organization-cash', 'permission' => null],
                    ['key' => 'finance.expenses', 'label' => 'Expenses', 'href' => '/finance/expenses', 'permission' => null],
                    ['key' => 'finance.overhead', 'label' => 'Overhead', 'href' => '/finance/overhead', 'permission' => null],
                ],
            ],
            [
                'key' => 'procurement',
                'label' => 'Procurement',
                'href' => '/procurement/suppliers',
                'active_path' => '/procurement',
                'permission' => 'procurement:read',
                'group' => 'Supply Chain',
                'children' => [
                    ['key' => 'procurement.suppliers', 'label' => 'Suppliers', 'href' => '/procurement/suppliers', 'permission' => null],
                    ['key' => 'procurement.purchase_orders', 'label' => 'Purchase Orders', 'href' => '/procurement/purchase-orders', 'permission' => null],
                    ['key' => 'procurement.goods_receipts', 'label' => 'Goods Receipts', 'href' => '/procurement/goods-receipts', 'permission' => null],
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory',
                'href' => '/inventory/items',
                'active_path' => '/inventory',
                'permission' => 'inventory:read',
                'group' => 'Supply Chain',
                'children' => [
                    ['key' => 'inventory.items', 'label' => 'Items', 'href' => '/inventory/items', 'permission' => null],
                    ['key' => 'inventory.balances', 'label' => 'On Hand', 'href' => '/inventory/balances', 'permission' => null],
                    ['key' => 'inventory.issues', 'label' => 'Hand Over', 'href' => '/inventory/issues', 'permission' => null],
                    ['key' => 'inventory.transactions', 'label' => 'History', 'href' => '/inventory/transactions', 'permission' => null],
                ],
            ],
            [
                'key' => 'payroll',
                'label' => 'Payroll',
                'href' => '/payroll/employees',
                'active_path' => '/payroll',
                'permission' => 'payroll:read',
                'group' => 'HR',
                'children' => [
                    ['key' => 'payroll.employees', 'label' => 'Employees', 'href' => '/payroll/employees', 'permission' => null],
                    ['key' => 'payroll.attendance', 'label' => 'Attendance', 'href' => '/payroll/attendance', 'permission' => null],
                    ['key' => 'payroll.generate', 'label' => 'Generate Payroll', 'href' => '/payroll/generate', 'permission' => null],
                    ['key' => 'payroll.runs', 'label' => 'Payroll Runs', 'href' => '/payroll/runs', 'permission' => null],
                ],
            ],
            [
                'key' => 'equipment',
                'label' => 'Equipment',
                'href' => '/equipment',
                'active_path' => '/equipment',
                'permission' => 'equipment:read',
                'group' => 'HR',
                'children' => [
                    ['key' => 'equipment.registry', 'label' => 'Registry', 'href' => '/equipment', 'permission' => null],
                    ['key' => 'equipment.assignments', 'label' => 'Assignments', 'href' => '/equipment/assignments', 'permission' => null],
                    ['key' => 'equipment.maintenance', 'label' => 'Maintenance', 'href' => '/equipment/maintenance', 'permission' => null],
                    ['key' => 'equipment.fuel', 'label' => 'Fuel Logs', 'href' => '/equipment/fuel', 'permission' => null],
                ],
            ],
            ['key' => 'reports', 'label' => 'Reports', 'href' => '/reports', 'permission' => 'reports:read', 'group' => 'Insights'],
            ['key' => 'audit', 'label' => 'Audit', 'href' => '/audit', 'permission' => 'audit:read', 'group' => 'Insights'],
            ['key' => 'admin', 'label' => 'Admin', 'href' => '/admin/users', 'permission' => 'auth:read', 'group' => 'Administration'],
        ];
    }

    /** @return list<string> */
    public static function hrefs(): array
    {
        $hrefs = [];

        foreach (self::items() as $item) {
            $hrefs[] = $item['href'];

            if (! empty($item['active_path'])) {
                $hrefs[] = $item['active_path'];
            }

            foreach ($item['children'] ?? [] as $child) {
                $hrefs[] = $child['href'];
            }
        }

        return array_values(array_unique($hrefs));
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_values(array_map(
            fn (array $item) => $item['key'],
            self::items(),
        ));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function childKeysByParent(): array
    {
        $map = [];

        foreach (self::items() as $item) {
            $children = $item['children'] ?? [];
            if ($children === []) {
                continue;
            }

            $map[$item['key']] = array_values(array_map(
                fn (array $child) => $child['key'],
                $children,
            ));
        }

        return $map;
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
            fn (string $role) => ! in_array($role, ['Platform Admin', 'System Administrator'], true),
        ));
    }
}