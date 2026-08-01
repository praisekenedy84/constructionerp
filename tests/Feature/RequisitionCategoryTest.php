<?php

namespace Tests\Feature;

use App\Models\RequisitionCategory;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create(['name' => 'Category Co', 'slug' => 'category-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@category-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@category-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();
    }

    public function test_defaults_are_seeded_and_listed(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@category-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/categories')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Categories/Index')
                ->has('categories.data')
                ->where('categories.data', function ($rows) {
                    $names = collect($rows)->pluck('name')->all();

                    return in_array('Materials', $names, true)
                        && in_array('Cash', $names, true)
                        && in_array('Labor', $names, true);
                })
            );
    }

    public function test_can_create_custom_category(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@category-co.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/categories', [
            'name' => 'Site Cement',
            'description' => 'Cement and binders',
            'is_active' => true,
            'sort_order' => 10,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'category-co')->firstOrFail();
        $tenant->run(function () {
            $category = RequisitionCategory::where('name', 'Site Cement')->first();
            $this->assertNotNull($category);
            $this->assertTrue($category->is_active);
            $this->assertSame(10, $category->sort_order);
        });
    }

    public function test_create_form_uses_active_categories(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'category-co')->firstOrFail();
        $tenant->run(function () {
            RequisitionCategory::create([
                'name' => 'Petty Cash Float',
                'description' => null,
                'is_active' => true,
                'sort_order' => 99,
            ]);

            RequisitionCategory::create([
                'name' => 'Hidden Other',
                'description' => null,
                'is_active' => false,
                'sort_order' => 100,
            ]);
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@category-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Create')
                ->has('categories')
                ->where('categories', function ($categories) {
                    $names = collect($categories)->pluck('name')->all();

                    return in_array('Petty Cash Float', $names, true)
                        && ! in_array('Hidden Other', $names, true);
                })
            );
    }
}
