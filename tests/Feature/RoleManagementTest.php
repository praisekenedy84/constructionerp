<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Support\MenuCatalog;
use App\Support\ModulePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsAdmin(string $slug = 'role-mgmt-co'): Tenant
    {
        $tenant = Tenant::create(['name' => 'Role Mgmt Co', 'slug' => $slug]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => "admin@{$slug}.local",
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => "admin@{$slug}.local",
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    public function test_admin_can_create_custom_role_and_it_appears_in_assignable_lists(): void
    {
        $this->loginAsAdmin('role-create-co');

        $this->post('/admin/roles', [
            'name' => 'Field Supervisor',
            'permissions' => ['projects:read', 'requisitions:read'],
        ])->assertRedirect();

        $this->get('/admin/permissions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('roles')
                ->where('roles', function ($roles) {
                    $custom = collect($roles)->firstWhere('name', 'Field Supervisor');

                    return $custom
                        && $custom['is_editable'] === true
                        && $custom['is_locked'] === false
                        && collect($custom['permissions'])->contains('projects:read');
                })
            );

        $this->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('assignable_roles', fn ($roles) => collect($roles)->contains('Field Supervisor'))
            );
    }

    public function test_admin_can_rename_role_and_migrate_menu_and_workflow_references(): void
    {
        $tenant = $this->loginAsAdmin('role-rename-co');

        $tenant->run(function () {
            Role::create(['name' => 'Old Title', 'guard_name' => 'web']);

            SystemSetting::updateOrCreate(
                ['key' => 'ui_settings'],
                [
                    'value' => [
                        'app_name' => 'CRF-ERP',
                        'tagline' => 'Test',
                        'nav_overrides' => [
                            'hidden' => [],
                            'role_hidden' => [
                                'Old Title' => ['/reports'],
                            ],
                            'order' => [],
                            'child_order' => [],
                        ],
                    ],
                    'updated_at' => now(),
                ],
            );

            WorkflowConfig::create([
                'project_id' => null,
                'level' => 9,
                'role_name' => 'Old Title',
                'threshold_min' => 0,
                'threshold_max' => 100,
                'escalation_hours' => 24,
            ]);

            app(AuthService::class)->createUser(Tenant::where('slug', 'role-rename-co')->first(), [
                'name' => 'Worker',
                'email' => 'worker@role-rename-co.local',
                'password' => 'password',
                'role' => 'Old Title',
            ]);
        });

        // AuthService ends tenancy; re-login as admin after side-effect user creation.
        auth()->logout();
        $this->post('/login', [
            'email' => 'admin@role-rename-co.local',
            'password' => 'password',
        ]);

        $this->patch('/admin/roles/'.rawurlencode('Old Title'), [
            'name' => 'New Title',
        ])->assertRedirect();

        $tenant->run(function () {
            $this->assertNull(Role::where('name', 'Old Title')->where('guard_name', 'web')->first());
            $this->assertNotNull(Role::where('name', 'New Title')->where('guard_name', 'web')->first());

            $worker = User::where('email', 'worker@role-rename-co.local')->firstOrFail();
            $this->assertTrue($worker->hasRole('New Title'));

            $settings = SystemSetting::where('key', 'ui_settings')->firstOrFail()->value;
            $this->assertArrayHasKey('New Title', $settings['nav_overrides']['role_hidden']);
            $this->assertArrayNotHasKey('Old Title', $settings['nav_overrides']['role_hidden']);

            $this->assertSame(
                1,
                WorkflowConfig::where('role_name', 'New Title')->count(),
            );
            $this->assertSame(
                0,
                WorkflowConfig::where('role_name', 'Old Title')->count(),
            );
        });
    }

    public function test_admin_can_edit_permissions_for_custom_and_seeded_roles(): void
    {
        $this->loginAsAdmin('role-perms-co');

        $this->post('/admin/roles', [
            'name' => 'Custom Viewer',
            'permissions' => [],
        ])->assertRedirect();

        $this->patch('/admin/permissions/roles/'.rawurlencode('Custom Viewer'), [
            'permissions' => ['projects:read', 'reports:read'],
        ])->assertRedirect();

        $this->patch('/admin/permissions/roles/Storekeeper', [
            'permissions' => ['inventory:read', 'inventory:create'],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'role-perms-co')->firstOrFail();
        $tenant->run(function () {
            $custom = Role::where('name', 'Custom Viewer')->where('guard_name', 'web')->firstOrFail();
            $this->assertEqualsCanonicalizing(
                ['projects:read', 'reports:read'],
                $custom->permissions->pluck('name')->all(),
            );

            $storekeeper = Role::where('name', 'Storekeeper')->where('guard_name', 'web')->firstOrFail();
            $this->assertEqualsCanonicalizing(
                ['inventory:read', 'inventory:create'],
                $storekeeper->permissions->pluck('name')->all(),
            );
        });
    }

    public function test_delete_role_blocked_when_users_assigned_and_allowed_when_unused(): void
    {
        $tenant = $this->loginAsAdmin('role-delete-co');

        $this->post('/admin/roles', [
            'name' => 'Temp Role',
            'permissions' => ['projects:read'],
        ])->assertRedirect();

        $tenant->run(function () {
            app(AuthService::class)->createUser(Tenant::where('slug', 'role-delete-co')->first(), [
                'name' => 'Temp User',
                'email' => 'temp@role-delete-co.local',
                'password' => 'password',
                'role' => 'Temp Role',
            ]);
        });

        auth()->logout();
        $this->post('/login', [
            'email' => 'admin@role-delete-co.local',
            'password' => 'password',
        ]);

        $this->from('/admin/permissions')
            ->delete('/admin/roles/'.rawurlencode('Temp Role'))
            ->assertRedirect('/admin/permissions')
            ->assertSessionHasErrors('role');

        $tenant->run(function () {
            $user = User::where('email', 'temp@role-delete-co.local')->firstOrFail();
            $user->syncRoles(['Site Engineer']);
        });

        $this->delete('/admin/roles/'.rawurlencode('Temp Role'))
            ->assertRedirect('/admin/permissions');

        $tenant->run(function () {
            $this->assertNull(Role::where('name', 'Temp Role')->where('guard_name', 'web')->first());
        });
    }

    public function test_locked_roles_cannot_be_renamed_deleted_or_have_permissions_edited(): void
    {
        $this->loginAsAdmin('role-lock-co');

        $this->from('/admin/permissions')
            ->patch('/admin/permissions/roles/'.rawurlencode('System Administrator'), [
                'permissions' => ['auth:read'],
            ])
            ->assertForbidden();

        $this->from('/admin/permissions')
            ->patch('/admin/roles/'.rawurlencode('System Administrator'), [
                'name' => 'Root Admin',
            ])
            ->assertForbidden();

        $this->from('/admin/permissions')
            ->delete('/admin/roles/'.rawurlencode('System Administrator'))
            ->assertForbidden();

        $this->post('/admin/roles', [
            'name' => 'Platform Admin',
            'permissions' => [],
        ])->assertSessionHasErrors('name');
    }

    public function test_soft_reset_does_not_remove_custom_roles_or_wipe_their_permissions(): void
    {
        $tenant = $this->loginAsAdmin('role-reset-co');

        $this->post('/admin/roles', [
            'name' => 'Custom Ops',
            'permissions' => ['projects:read', 'sales:read'],
        ])->assertRedirect();

        $this->patch('/admin/permissions/roles/Storekeeper', [
            'permissions' => ['inventory:read'],
        ])->assertRedirect();

        $this->post('/admin/permissions/sync')->assertRedirect();

        $tenant->run(function () {
            $custom = Role::where('name', 'Custom Ops')->where('guard_name', 'web')->firstOrFail();
            $this->assertEqualsCanonicalizing(
                ['projects:read', 'sales:read'],
                $custom->permissions->pluck('name')->all(),
            );

            $storekeeper = Role::where('name', 'Storekeeper')->where('guard_name', 'web')->firstOrFail();
            $expected = ModulePermission::matrix()['Storekeeper'];
            $this->assertEqualsCanonicalizing(
                $expected,
                $storekeeper->permissions->pluck('name')->all(),
            );

            $sa = Role::where('name', 'System Administrator')->where('guard_name', 'web')->firstOrFail();
            $this->assertEqualsCanonicalizing(
                ModulePermission::allPermissionNames(),
                $sa->permissions->pluck('name')->all(),
            );
        });
    }

    public function test_tenant_roles_helper_is_database_driven(): void
    {
        $tenant = Tenant::create(['name' => 'Helper Co', 'slug' => 'helper-co']);

        $tenant->run(function () {
            Role::firstOrCreate(['name' => 'System Administrator', 'guard_name' => 'web']);
            Role::firstOrCreate(['name' => 'Platform Admin', 'guard_name' => 'web']);
            Role::create(['name' => 'Night Watch', 'guard_name' => 'web']);

            $tenantRoles = MenuCatalog::tenantRoles();
            $this->assertContains('Night Watch', $tenantRoles);
            $this->assertContains('System Administrator', $tenantRoles);
            $this->assertNotContains('Platform Admin', $tenantRoles);

            $this->assertContains('Night Watch', MenuCatalog::editablePermissionRoles());
            $this->assertNotContains('System Administrator', MenuCatalog::editablePermissionRoles());
        });
    }
}
