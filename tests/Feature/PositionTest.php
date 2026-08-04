<?php

namespace Tests\Feature;

use App\Models\Position;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PositionTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create(['name' => 'Position Co', 'slug' => 'position-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@position-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@position-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();
    }

    public function test_defaults_are_seeded_and_listed(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@position-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/positions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Positions/Index')
                ->has('positions.data')
                ->where('positions.data', function ($rows) {
                    $names = collect($rows)->pluck('name')->all();

                    return in_array('Site Foreman', $names, true)
                        && in_array('Project Manager', $names, true)
                        && in_array('Storekeeper', $names, true);
                })
            );
    }

    public function test_can_create_custom_position(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@position-co.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/positions', [
            'name' => 'Safety Officer',
            'description' => 'HSE site lead',
            'is_active' => true,
            'sort_order' => 10,
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'position-co')->firstOrFail();
        $tenant->run(function () {
            $position = Position::where('name', 'Safety Officer')->first();
            $this->assertNotNull($position);
            $this->assertTrue($position->is_active);
            $this->assertSame(10, $position->sort_order);
        });
    }

    public function test_create_form_uses_active_positions(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'position-co')->firstOrFail();
        $tenant->run(function () {
            Position::create([
                'name' => 'Plant Operator',
                'description' => null,
                'is_active' => true,
                'sort_order' => 99,
            ]);

            Position::create([
                'name' => 'Hidden Position',
                'description' => null,
                'is_active' => false,
                'sort_order' => 100,
            ]);
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@position-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Create')
                ->has('positions')
                ->where('positions', function ($positions) {
                    $names = collect($positions)->pluck('name')->all();

                    return in_array('Plant Operator', $names, true)
                        && ! in_array('Hidden Position', $names, true);
                })
            );
    }
}
