<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TenantAdminManagementTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsTenantAdmin(): Tenant
    {
        $tenant = Tenant::create(['name' => 'Admin Co', 'slug' => 'admin-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Tenant Admin',
            'email' => 'admin@admin-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@admin-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@admin-co.local',
            'password' => 'password',
        ]);

        return $tenant;
    }

    public function test_tenant_admin_can_update_user_role(): void
    {
        $this->loginAsTenantAdmin();

        $this->patch('/admin/users/2', [
            'name' => 'Engineer Updated',
            'email' => 'engineer@admin-co.local',
            'role' => 'Project Manager',
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'admin-co')->first();
        $tenant->run(function () {
            $user = User::find(2);
            $this->assertSame('Engineer Updated', $user->name);
            $this->assertTrue($user->hasRole('Project Manager'));
        });
    }

    public function test_tenant_admin_can_update_role_permissions(): void
    {
        $this->loginAsTenantAdmin();

        $this->patch('/admin/permissions/roles/Storekeeper', [
            'permissions' => ['inventory:read', 'inventory:create'],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'admin-co')->first();
        $tenant->run(function () {
            $role = Role::findByName('Storekeeper', 'web');
            $this->assertEqualsCanonicalizing(
                ['inventory:read', 'inventory:create'],
                $role->permissions->pluck('name')->all(),
            );
        });
    }

    public function test_tenant_admin_cannot_delete_self(): void
    {
        $this->loginAsTenantAdmin();

        $this->delete('/admin/users/1')->assertSessionHasErrors();
    }

    public function test_non_admin_cannot_access_user_management(): void
    {
        $tenant = Tenant::create(['name' => 'Staff Co', 'slug' => 'staff-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'eng@staff-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'eng@staff-co.local',
            'password' => 'password',
        ]);

        $this->get('/admin/users')
            ->assertForbidden()
            ->assertInertia(fn ($page) => $page
                ->component('Error')
                ->where('status', 403)
            );
    }
}
