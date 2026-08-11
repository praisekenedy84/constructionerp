<?php

namespace App\Services;

use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WorkflowConfig;
use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionService
{
    /**
     * Sync the permission catalog and optionally re-apply matrix template defaults.
     * Custom (non-matrix) roles are never deleted or wiped.
     * Locked roles always receive the full catalog.
     */
    public function syncTenantPermissions(bool $applyTemplateDefaults = true): void
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

        $this->ensureLockedRolesHaveFullAccess($valid);

        if (! $applyTemplateDefaults) {
            return;
        }

        foreach (ModulePermission::matrix() as $roleName => $permissions) {
            if (MenuCatalog::isLockedRole($roleName)) {
                continue;
            }

            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

            if ($role) {
                $role->syncPermissions($permissions);
            }
        }
    }

    /**
     * @param  list<string>  $permissions
     */
    public function createRole(string $name, array $permissions = []): Role
    {
        $name = trim($name);

        if ($name === '' || MenuCatalog::isLockedRole($name)) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved and cannot be created.',
            ]);
        }

        if (Role::where('name', $name)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages([
                'name' => "A role named “{$name}” already exists.",
            ]);
        }

        $valid = ModulePermission::allPermissionNames();
        $permissions = array_values(array_intersect($permissions, $valid));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($valid as $permissionName) {
            Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
        }

        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    public function renameRole(string $from, string $to): Role
    {
        $from = trim($from);
        $to = trim($to);

        if (MenuCatalog::isLockedRole($from)) {
            throw ValidationException::withMessages([
                'name' => 'This role cannot be renamed.',
            ]);
        }

        if ($to === '' || MenuCatalog::isLockedRole($to)) {
            throw ValidationException::withMessages([
                'name' => 'This role name is reserved.',
            ]);
        }

        if ($from === $to) {
            return Role::where('name', $from)->where('guard_name', 'web')->firstOrFail();
        }

        if (Role::where('name', $to)->where('guard_name', 'web')->exists()) {
            throw ValidationException::withMessages([
                'name' => "A role named “{$to}” already exists.",
            ]);
        }

        return DB::transaction(function () use ($from, $to) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role = Role::where('name', $from)->where('guard_name', 'web')->firstOrFail();
            $role->name = $to;
            $role->save();

            $this->migrateRoleHiddenKeys($from, $to);
            WorkflowConfig::query()->where('role_name', $from)->update(['role_name' => $to]);

            return $role->load('permissions');
        });
    }

    public function deleteRole(string $name): void
    {
        $name = trim($name);

        if (MenuCatalog::isLockedRole($name)) {
            throw ValidationException::withMessages([
                'role' => 'This role cannot be deleted.',
            ]);
        }

        $role = Role::where('name', $name)->where('guard_name', 'web')->firstOrFail();

        if ($this->countUsersWithRole($name) > 0) {
            throw ValidationException::withMessages([
                'role' => 'Cannot delete a role that still has users assigned. Reassign those users first.',
            ]);
        }

        DB::transaction(function () use ($role, $name) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $role->syncPermissions([]);
            $role->delete();

            $this->removeRoleHiddenKey($name);
        });
    }

    /** @param  list<string>  $permissions */
    public function updateRolePermissions(string $roleName, array $permissions): Role
    {
        if (MenuCatalog::isLockedRole($roleName)) {
            throw new \InvalidArgumentException("Role [{$roleName}] permissions cannot be edited.");
        }

        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();

        if (! $role) {
            throw new \InvalidArgumentException("Role [{$roleName}] was not found.");
        }

        $valid = ModulePermission::allPermissionNames();
        $permissions = array_values(array_intersect($permissions, $valid));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $role->syncPermissions($permissions);

        return $role->load('permissions');
    }

    public function countUsersWithRole(string $roleName): int
    {
        return User::role($roleName)->count();
    }

    /** @param  list<string>  $allPermissions */
    private function ensureLockedRolesHaveFullAccess(array $allPermissions): void
    {
        foreach (MenuCatalog::lockedRoleNames() as $roleName) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($allPermissions);
        }
    }

    private function migrateRoleHiddenKeys(string $from, string $to): void
    {
        $setting = SystemSetting::where('key', 'ui_settings')->first();

        if (! $setting || ! is_array($setting->value)) {
            return;
        }

        $value = $setting->value;
        $overrides = $value['nav_overrides'] ?? [];
        $roleHidden = $overrides['role_hidden'] ?? [];

        if (! is_array($roleHidden) || ! array_key_exists($from, $roleHidden)) {
            return;
        }

        if (! array_key_exists($to, $roleHidden)) {
            $roleHidden[$to] = $roleHidden[$from];
        }

        unset($roleHidden[$from]);
        $overrides['role_hidden'] = $roleHidden;
        $value['nav_overrides'] = $overrides;

        $setting->value = $value;
        $setting->updated_at = now();
        $setting->save();
    }

    private function removeRoleHiddenKey(string $name): void
    {
        $setting = SystemSetting::where('key', 'ui_settings')->first();

        if (! $setting || ! is_array($setting->value)) {
            return;
        }

        $value = $setting->value;
        $overrides = $value['nav_overrides'] ?? [];
        $roleHidden = $overrides['role_hidden'] ?? [];

        if (! is_array($roleHidden) || ! array_key_exists($name, $roleHidden)) {
            return;
        }

        unset($roleHidden[$name]);
        $overrides['role_hidden'] = $roleHidden;
        $value['nav_overrides'] = $overrides;

        $setting->value = $value;
        $setting->updated_at = now();
        $setting->save();
    }
}
