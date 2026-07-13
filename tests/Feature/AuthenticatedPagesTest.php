<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedPagesTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsTenantAdmin(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Test Co',
            'slug' => 'test-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@test.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    public function test_dashboard_loads_after_login(): void
    {
        $this->loginAsTenantAdmin();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard')
                ->has('stats.active_projects')
                ->has('stats.total_projects')
            );
    }

    public function test_projects_page_loads_after_login(): void
    {
        $this->loginAsTenantAdmin();

        $this->get('/projects')->assertOk();
    }

    public function test_session_persists_with_database_session_driver(): void
    {
        config([
            'session.driver' => 'database',
            'session.connection' => 'central',
        ]);

        $this->loginAsTenantAdmin();

        $this->assertAuthenticated();
        $this->assertNotNull(session('tenant_id'));

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_finance_and_payroll_hubs_load_without_projects(): void
    {
        $this->loginAsTenantAdmin();

        $this->get('/finance')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Hub')
                ->where('project', null)
            );

        $this->get('/payroll')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Payroll/Hub')
                ->where('project', null)
            );

        $this->get('/finance/approvals')->assertOk();
        $this->get('/finance/expenses')->assertOk();
        $this->get('/finance/overhead')->assertOk();
    }
}
