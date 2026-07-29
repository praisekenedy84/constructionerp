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

        $valid = ModulePermission::allPermissionNames();

        foreach ($valid as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Drop obsolete capabilities that no longer exist in the catalog.
        Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', $valid)
            ->delete();

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

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->firstOrFail();
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }
}
