<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Support\ModulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SystemAdministratorPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_administrator_has_every_module_permission(): void
    {
        $tenant = Tenant::create([
            'name' => 'Admin Perm Co',
            'slug' => 'admin-perm-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@perm.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            // Even if role permissions were stripped, admin still has full access.
            $role = Role::where('name', 'System Administrator')->where('guard_name', 'web')->firstOrFail();
            $role->syncPermissions(['auth:read']);

            $admin = User::where('email', 'admin@perm.local')->firstOrFail();

            foreach (ModulePermission::allPermissionNames() as $permission) {
                [$module, $action] = explode(':', $permission, 2);
                $this->assertTrue(
                    $admin->hasModulePermission($module, $action),
                    "Expected System Administrator to have {$permission}",
                );
            }

            $this->assertSame(
                ModulePermission::allPermissionNames(),
                $admin->modulePermissions(),
            );
        });
    }

    public function test_system_administrator_role_permissions_are_not_editable(): void
    {
        $tenant = Tenant::create([
            'name' => 'Admin Lock Co',
            'slug' => 'admin-lock-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@lock.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@lock.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->from('/admin/permissions')
            ->patch('/admin/permissions/roles/System Administrator', [
                'permissions' => ['auth:read'],
            ])
            ->assertForbidden();
    }
}
