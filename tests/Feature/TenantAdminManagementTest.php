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

    public function test_tenant_admin_can_create_company_staff_without_project(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/admin/people', [
            'create_user' => false,
            'create_staff' => true,
            'name' => 'Company Worker',
            'employee_no' => 'EMP-200',
            'job_role' => 'General Labourer',
            'pay_structure' => 'daily',
            'daily_rate' => '35000',
            'project_ids' => [],
        ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Staff member added.');

        $tenant = Tenant::where('slug', 'admin-co')->firstOrFail();
        $tenant->run(function (): void {
            $employee = Employee::where('employee_no', 'EMP-200')->first();
            $this->assertNotNull($employee);
            $this->assertNull($employee->project_id);
            $this->assertCount(0, $employee->projects);
        });
    }

    public function test_tenant_admin_can_assign_staff_to_multiple_projects(): void
    {
        $this->loginAsTenantAdmin();

        $tenant = Tenant::where('slug', 'admin-co')->firstOrFail();
        $projectIds = [];

        $tenant->run(function () use (&$projectIds): void {
            foreach (['PRJ-A', 'PRJ-B'] as $code) {
                $projectIds[] = Project::create([
                    'code' => $code,
                    'name' => "Site {$code}",
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
            }
        });

        $this->post('/admin/people', [
            'create_user' => false,
            'create_staff' => true,
            'name' => 'Multi Site Worker',
            'employee_no' => 'EMP-201',
            'job_role' => 'Foreman',
            'pay_structure' => 'monthly',
            'monthly_salary' => '900000',
            'project_ids' => $projectIds,
        ])->assertRedirect();

        $tenant->run(function () use ($projectIds): void {
            $employee = Employee::where('employee_no', 'EMP-201')->firstOrFail();
            $this->assertSame($projectIds[0], $employee->project_id);
            $this->assertEqualsCanonicalizing($projectIds, $employee->projects()->pluck('projects.id')->all());
        });
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
            $this->assertSame([$projectId], $employee->projects()->pluck('projects.id')->all());
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
