<?php

namespace App\Support;

class ModulePermission
{
    public const MODULES = [
        'projects',
        'boq',
        'budgets',
        'audit',
        'auth',
        'requisitions',
        'procurement',
        'inventory',
        'payroll',
        'equipment',
        'reports',
        'valuations',
    ];

    public const ACTIONS = [
        'read',
        'create',
        'update',
        'approve',
        'amend',
        'reject',
        'fulfill',
        'delete-soft',
    ];

    /** @return array<string, list<string>> */
    public static function matrix(): array
    {
        $all = self::allActionsAllModules();

        return [
            'Platform Admin' => $all,
            'System Administrator' => $all,
            'Managing Director' => $all,
            'Finance Manager' => self::fullOn([
                'projects', 'boq', 'budgets', 'audit', 'requisitions', 'procurement', 'reports', 'valuations',
            ]),
            'Manager' => array_merge(
                self::readOnly(['projects', 'boq', 'budgets', 'requisitions', 'reports', 'valuations']),
                self::only(['projects'], ['update']),
                self::only(['budgets'], ['approve', 'reject']),
            ),
            'Accountant' => self::fullOn(['projects', 'budgets', 'reports', 'valuations']),
            'Quantity Surveyor' => self::fullOn(['projects', 'boq', 'reports', 'valuations']),
            'Procurement Officer' => self::fullOn(['procurement', 'requisitions', 'boq']),
            'Storekeeper' => array_merge(
                self::fullOn(['inventory', 'boq']),
                self::only(['requisitions'], ['read', 'update', 'fulfill']),
            ),
            'Project Manager' => self::fullOn(['projects', 'boq', 'requisitions', 'reports', 'valuations']),
            'Site Engineer' => array_merge(
                self::fullOn(['boq']),
                self::only(['requisitions'], ['create', 'read', 'update']),
            ),
            'HR Officer' => self::fullOn(['payroll', 'equipment']),
            'Auditor' => array_merge(
                self::fullOn(['audit']),
                self::readOnly(['reports', 'projects', 'boq', 'budgets', 'requisitions']),
            ),
        ];
    }

    /** @return list<string> */
    public static function allPermissionNames(): array
    {
        $names = [];

        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
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
    private static function allActionsAllModules(): array
    {
        $perms = [];

        foreach (self::MODULES as $module) {
            foreach (self::ACTIONS as $action) {
                $perms[] = self::name($module, $action);
            }
        }

        return $perms;
    }

    /** @param  list<string>  $modules */
    private static function fullOn(array $modules): array
    {
        $perms = [];

        foreach ($modules as $module) {
            foreach (self::ACTIONS as $action) {
                $perms[] = self::name($module, $action);
            }
        }

        return $perms;
    }

    /** @param  list<string>  $modules */
    private static function readOnly(array $modules): array
    {
        return array_map(fn (string $module) => self::name($module, 'read'), $modules);
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
                $perms[] = self::name($module, $action);
            }
        }

        return $perms;
    }
}
