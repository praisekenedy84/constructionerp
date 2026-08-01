<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create(['name' => 'Dept Co', 'slug' => 'dept-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@dept-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@dept-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();
    }

    public function test_defaults_are_seeded_and_listed(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@dept-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/departments')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Departments/Index')
                ->has('departments.data')
                ->where('departments.data', function ($rows) {
                    $names = collect($rows)->pluck('name')->all();

                    return in_array('Site Operations', $names, true)
                        && in_array('Procurement', $names, true)
                        && in_array('Finance', $names, true);
                })
            );
    }

    public function test_can_create_custom_department(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@dept-co.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/departments', [
            'name' => 'Quality Control',
            'description' => 'QA / QC site office',
            'is_active' => true,
            'sort_order' => 10,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'dept-co')->firstOrFail();
        $tenant->run(function () {
            $department = Department::where('name', 'Quality Control')->first();
            $this->assertNotNull($department);
            $this->assertTrue($department->is_active);
            $this->assertSame(10, $department->sort_order);
        });
    }

    public function test_create_form_uses_active_departments(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'dept-co')->firstOrFail();
        $tenant->run(function () {
            Department::create([
                'name' => 'Survey Team',
                'description' => null,
                'is_active' => true,
                'sort_order' => 99,
            ]);

            Department::create([
                'name' => 'Hidden Dept',
                'description' => null,
                'is_active' => false,
                'sort_order' => 100,
            ]);
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@dept-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Create')
                ->has('departments')
                ->where('departments', function ($departments) {
                    $names = collect($departments)->pluck('name')->all();

                    return in_array('Survey Team', $names, true)
                        && ! in_array('Hidden Dept', $names, true);
                })
            );
    }
}
