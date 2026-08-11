<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
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

    public function test_tenant_admin_can_create_user_and_staff_together(): void
    {
        $this->loginAsTenantAdmin();

        $tenant = Tenant::where('slug', 'admin-co')->firstOrFail();
        $projectId = null;

        $tenant->run(function () use (&$projectId): void {
            $projectId = Project::create([
                'code' => 'PRJ-ADM',
                'name' => 'Admin Site',
                'client' => 'Client',
                'location' => 'Dar',
                'contract_amount' => '1000000',
                'wht_percentage' => '5',
                'net_budget' => '950000',
                'physical_progress_pct' => '0',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYear()->toDateString(),
                'status' => 'active',
            ])->id;
        });

        $this->post('/admin/people', [
            'create_user' => true,
            'create_staff' => true,
            'name' => 'Combined Person',
            'email' => 'combined@admin-co.local',
            'password' => 'password',
            'password_confirmation' => 'password',
            'access_role' => 'Site Engineer',
            'employee_no' => 'EMP-100',
            'job_role' => 'Foreman',
            'pay_structure' => 'monthly',
            'monthly_salary' => '750000',
            'project_id' => $projectId,
        ])
            ->assertRedirect()
            ->assertSessionHas('success', 'User and staff member created.');

        $tenant->run(function () use ($projectId): void {
            $user = User::where('email', 'combined@admin-co.local')->first();
            $this->assertNotNull($user);
            $this->assertTrue($user->hasRole('Site Engineer'));

            $employee = Employee::where('employee_no', 'EMP-100')->first();
            $this->assertNotNull($employee);
            $this->assertSame('Combined Person', $employee->name);
            $this->assertSame('Foreman', $employee->role);
            $this->assertSame($user->id, $employee->user_id);
            $this->assertSame($projectId, $employee->project_id);
        });
    }

    public function test_combined_create_requires_at_least_one_target(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/admin/people', [
            'create_user' => false,
            'create_staff' => false,
            'name' => 'Nobody',
        ])->assertSessionHasErrors('create_user');
    }
}
