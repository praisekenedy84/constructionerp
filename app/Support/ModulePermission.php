<?php

namespace App\Support;

/**
 * Granular capability catalog: each module declares only the actions that apply.
 * Permission names remain "{module}:{action}" for Spatie + the admin matrix.
 */
class ModulePermission
{
    /**
     * Module → allowed actions (capability keys).
     *
     * @var array<string, list<string>>
     */
    public const CATALOG = [
        'projects' => ['read', 'create', 'update', 'delete-soft'],
        'boq' => ['read', 'create', 'update', 'import', 'approve'],
        'budgets' => ['read', 'create', 'update', 'approve', 'reject', 'receive', 'override'],
        'audit' => ['read'],
        'auth' => ['read', 'create', 'update'],
        'settings' => ['read', 'update'],
        'requisitions' => [
            'read', 'create', 'update', 'publish', 'approve', 'amend', 'reject',
            'fulfill', 'cancel', 'close', 'override',
        ],
        'procurement' => ['read', 'create', 'update', 'approve', 'receive'],
        'inventory' => ['read', 'create', 'update', 'receive', 'issue', 'adjust'],
        'payroll' => ['read', 'create', 'update', 'approve'],
        'equipment' => ['read', 'create', 'update', 'assign'],
        'reports' => ['read', 'export', 'schedule'],
        'valuations' => ['read', 'create', 'update', 'approve'],
    ];

    /** @var list<string> */
    public const MODULES = [
        'projects',
        'boq',
        'budgets',
        'audit',
        'auth',
        'settings',
        'requisitions',
        'procurement',
        'inventory',
        'payroll',
        'equipment',
        'reports',
        'valuations',
    ];

    /** Flat union of all actions (for UI headers / validation helpers). */
    public const ACTIONS = [
        'read',
        'create',
        'update',
        'publish',
        'approve',
        'amend',
        'reject',
        'fulfill',
        'cancel',
        'close',
        'receive',
        'issue',
        'adjust',
        'assign',
        'import',
        'export',
        'schedule',
        'override',
        'delete-soft',
    ];

    /** @return array<string, list<string>> */
    public static function catalog(): array
    {
        return self::CATALOG;
    }

    /** @return array<string, string> */
    public static function moduleLabels(): array
    {
        return [
            'projects' => 'Projects',
            'boq' => 'BOQ',
            'budgets' => 'Finance / Cash',
            'audit' => 'Audit log',
            'auth' => 'Users & access',
            'settings' => 'Company settings',
            'requisitions' => 'Requisitions',
            'procurement' => 'Procurement',
            'inventory' => 'Inventory',
            'payroll' => 'Payroll & HR',
            'equipment' => 'Equipment',
            'reports' => 'Reports',
            'valuations' => 'Valuations',
        ];
    }

    /** @return array<string, string> */
    public static function actionLabels(): array
    {
        return [
            'read' => 'View',
            'create' => 'Create',
            'update' => 'Edit',
            'publish' => 'Publish',
            'approve' => 'Approve',
            'amend' => 'Amend',
            'reject' => 'Reject',
            'fulfill' => 'Fulfill',
            'cancel' => 'Cancel',
            'close' => 'Close',
            'receive' => 'Receive',
            'issue' => 'Issue / hand over',
            'adjust' => 'Adjust stock',
            'assign' => 'Assign',
            'import' => 'Import',
            'export' => 'Export',
            'schedule' => 'Schedule',
            'override' => 'Override limits',
            'delete-soft' => 'Archive',
        ];
    }

    /** @return array<string, string> */
    public static function actionDescriptions(): array
    {
        return [
            'read' => 'Open lists and detail pages',
            'create' => 'Add new records',
            'update' => 'Change existing records',
            'publish' => 'Submit drafts into the approval workflow',
            'approve' => 'Approve pending items',
            'amend' => 'Approve with a changed amount',
            'reject' => 'Reject pending items',
            'fulfill' => 'Disburse cash or issue stock against an approved request',
            'cancel' => 'Cancel an approved or in-progress request',
            'close' => 'Close a fulfilled request with documents',
            'receive' => 'Record funds or goods received',
            'issue' => 'Hand over stock from store',
            'adjust' => 'Manual stock quantity corrections',
            'assign' => 'Assign equipment or people to work',
            'import' => 'Bulk-import BOQ or data files',
            'export' => 'Download reports and extracts',
            'schedule' => 'Configure scheduled report delivery',
            'override' => 'Bypass BOQ quantity or cash-on-hand checks',
            'delete-soft' => 'Archive / soft-delete records',
        ];
    }

    public static function moduleAllows(string $module, string $action): bool
    {
        return in_array($action, self::CATALOG[$module] ?? [], true);
    }

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        $all = self::allPermissionNames();

        return [
            'Platform Admin' => $all,
            'System Administrator' => $all,
            'Managing Director' => $all,
            'Finance Manager' => array_values(array_unique(array_merge(
                self::fullOn(['projects', 'boq', 'budgets', 'audit', 'requisitions', 'procurement', 'reports', 'valuations']),
                self::only(['inventory'], ['read', 'receive']),
                self::only(['payroll'], ['read', 'approve']),
                self::only(['settings'], ['read']),
            ))),
            'Manager' => array_values(array_unique(array_merge(
                self::readOnly(['projects', 'boq', 'budgets', 'requisitions', 'reports', 'valuations']),
                self::only(['projects'], ['update']),
                self::only(['budgets'], ['approve', 'reject', 'receive']),
                self::only(['requisitions'], ['approve', 'reject']),
            ))),
            'Accountant' => array_values(array_unique(array_merge(
                self::fullOn(['projects', 'budgets', 'reports', 'valuations']),
                self::only(['requisitions'], ['read']),
            ))),
            'Quantity Surveyor' => array_values(array_unique(array_merge(
                self::fullOn(['projects', 'boq', 'reports', 'valuations']),
                self::only(['requisitions'], ['read']),
            ))),
            'Procurement Officer' => array_values(array_unique(array_merge(
                self::fullOn(['procurement', 'boq']),
                self::only(['requisitions'], ['read', 'fulfill']),
                self::only(['inventory'], ['read', 'receive']),
            ))),
            'Storekeeper' => array_values(array_unique(array_merge(
                self::fullOn(['inventory']),
                self::only(['boq'], ['read']),
                self::only(['requisitions'], ['read', 'fulfill']),
                self::only(['procurement'], ['read', 'receive']),
            ))),
            'Project Manager' => array_values(array_unique(array_merge(
                self::fullOn(['projects', 'boq', 'requisitions', 'reports', 'valuations']),
                self::only(['budgets'], ['read']),
                self::only(['equipment'], ['read', 'assign']),
            ))),
            'Site Engineer' => array_values(array_unique(array_merge(
                self::only(['boq'], ['read']),
                self::only(['projects'], ['read']),
                self::only(['requisitions'], ['read', 'create', 'update', 'publish', 'cancel']),
                self::only(['equipment'], ['read']),
                self::only(['inventory'], ['read']),
                self::only(['payroll'], ['read', 'update']),
            ))),
            'HR Officer' => array_values(array_unique(array_merge(
                self::fullOn(['payroll', 'equipment']),
                self::only(['projects'], ['read']),
            ))),
            'Auditor' => array_values(array_unique(array_merge(
                self::fullOn(['audit']),
                self::readOnly(['reports', 'projects', 'boq', 'budgets', 'requisitions', 'procurement', 'inventory', 'payroll', 'valuations']),
                self::only(['reports'], ['export']),
            ))),
        ];
    }

    /** @return list<string> */
    public static function allPermissionNames(): array
    {
        $names = [];

        foreach (self::CATALOG as $module => $actions) {
            foreach ($actions as $action) {
                $names[] = self::name($module, $action);
            }
        }

        return $names;
    }

    public static function name(string $module, string $action): string
    {
        return "{$module}:{$action}";
    }

    /** @param  list<string>  $modules */
    private static function fullOn(array $modules): array
    {
        $perms = [];

        foreach ($modules as $module) {
            foreach (self::CATALOG[$module] ?? [] as $action) {
                $perms[] = self::name($module, $action);
            }
        }

        return $perms;
    }

    /** @param  list<string>  $modules */
    private static function readOnly(array $modules): array
    {
        return self::only($modules, ['read']);
    }

    /**
     * @param  list<string>  $modules
     * @param  list<string>  $actions
     * @return list<string>
     */
    private static function only(array $modules, array $actions): array
    {
        $perms = [];

        foreach ($modules as $module) {
            foreach ($actions as $action) {
                if (self::moduleAllows($module, $action)) {
                    $perms[] = self::name($module, $action);
                }
            }
        }

        return $perms;
    }
}
