<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\Unit;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionUnitTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create(['name' => 'Unit Co', 'slug' => 'unit-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@unit-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@unit-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();
    }

    public function test_defaults_are_seeded_and_listed(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@unit-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/units')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Units/Index')
                ->has('units.data')
                ->where('units.data', function ($rows) {
                    $names = collect($rows)->pluck('name')->all();

                    return in_array('pcs', $names, true)
                        && in_array('bag', $names, true)
                        && in_array('day', $names, true);
                })
            );
    }

    public function test_can_create_custom_unit(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@unit-co.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/units', [
            'name' => 'drum',
            'description' => '200L drum',
            'is_active' => true,
            'sort_order' => 20,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'unit-co')->firstOrFail();
        $tenant->run(function () {
            $unit = Unit::where('name', 'drum')->first();
            $this->assertNotNull($unit);
            $this->assertTrue($unit->is_active);
            $this->assertSame(20, $unit->sort_order);
        });
    }

    public function test_create_form_uses_active_units(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'unit-co')->firstOrFail();
        $tenant->run(function () {
            Unit::create([
                'name' => 'bundle',
                'description' => null,
                'is_active' => true,
                'sort_order' => 99,
            ]);

            Unit::create([
                'name' => 'hidden-unit',
                'description' => null,
                'is_active' => false,
                'sort_order' => 100,
            ]);
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@unit-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Create')
                ->has('units')
                ->where('units', function ($units) {
                    $names = collect($units)->pluck('name')->all();

                    return in_array('bundle', $names, true)
                        && ! in_array('hidden-unit', $names, true);
                })
            );
    }
}
