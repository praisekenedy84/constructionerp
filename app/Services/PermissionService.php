<?php

namespace App\Services;

use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionService
{
    public function syncTenantPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (ModulePermission::allPermissionNames() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (ModulePermission::matrix() as $roleName => $permissions) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role) {
                $role->syncPermissions($permissions);
            }
        }
    }

    /** @param  list<string>  $permissions */
    public function updateRolePermissions(string $roleName, array $permissions): Role
    {
        if (! in_array($roleName, MenuCatalog::editablePermissionRoles(), true)) {
            throw new \InvalidArgumentException("Role [{$roleName}] permissions cannot be edited.");
        }

        $valid = ModulePermission::allPermissionNames();
        $permissions = array_values(array_intersect($permissions, $valid));

        if ($roleName === 'System Administrator' && ! $this->hasAdminMinimum($permissions)) {
            throw new \InvalidArgumentException('System Administrator must retain auth:read and auth:update.');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->firstOrFail();
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    /** @param  list<string>  $permissions */
    private function hasAdminMinimum(array $permissions): bool
    {
        return in_array('auth:read', $permissions, true)
            && in_array('auth:update', $permissions, true);
    }
}
