<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
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
            'expense_type' => ExpenseCategory::Direct->value,
            'is_active' => true,
            'sort_order' => 10,
        ])->assertRedirect();

        $this->post('/requisitions/categories', [
            'name' => 'Office Supplies',
            'description' => 'Admin stationery',
            'expense_type' => ExpenseCategory::Indirect->value,
            'is_active' => true,
            'sort_order' => 11,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'category-co')->firstOrFail();
        $tenant->run(function () {
            $direct = RequisitionCategory::where('name', 'Site Cement')->first();
            $this->assertNotNull($direct);
            $this->assertTrue($direct->is_active);
            $this->assertSame(10, $direct->sort_order);
            $this->assertSame(ExpenseCategory::Direct, $direct->expense_type);

            $indirect = RequisitionCategory::where('name', 'Office Supplies')->first();
            $this->assertNotNull($indirect);
            $this->assertSame(ExpenseCategory::Indirect, $indirect->expense_type);
        });
    }

    public function test_same_name_allowed_for_different_expense_types(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@category-co.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/categories', [
            'name' => 'Transport',
            'expense_type' => ExpenseCategory::Direct->value,
            'is_active' => true,
            'sort_order' => 1,
        ])->assertRedirect();

        $this->post('/requisitions/categories', [
            'name' => 'Transport',
            'expense_type' => ExpenseCategory::Indirect->value,
            'is_active' => true,
            'sort_order' => 2,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'category-co')->firstOrFail();
        $tenant->run(function () {
            $this->assertSame(
                2,
                RequisitionCategory::query()->where('name', 'Transport')->count()
            );
        });
    }

    public function test_create_form_includes_expense_type_on_categories(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'category-co')->firstOrFail();
        $tenant->run(function () {
            RequisitionCategory::create([
                'name' => 'Petty Cash Float',
                'description' => null,
                'expense_type' => ExpenseCategory::Indirect,
                'is_active' => true,
                'sort_order' => 99,
            ]);

            RequisitionCategory::create([
                'name' => 'Site Cement',
                'description' => null,
                'expense_type' => ExpenseCategory::Direct,
                'is_active' => true,
                'sort_order' => 98,
            ]);

            RequisitionCategory::create([
                'name' => 'Hidden Other',
                'description' => null,
                'expense_type' => ExpenseCategory::Direct,
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
                    $byName = collect($categories)->keyBy('name');

                    return $byName->has('Petty Cash Float')
                        && $byName->has('Site Cement')
                        && ! $byName->has('Hidden Other')
                        && ($byName['Petty Cash Float']['expense_type'] ?? null) === 'indirect'
                        && ($byName['Site Cement']['expense_type'] ?? null) === 'direct';
                })
            );
    }
}
