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

    public function test_finance_and_payroll_module_roots_redirect_to_first_subfeature(): void
    {
        $this->loginAsTenantAdmin();

        $this->get('/finance')->assertRedirect('/finance/approvals');
        $this->get('/payroll')->assertRedirect('/payroll/employees');
        $this->get('/procurement')->assertRedirect('/procurement/suppliers');
        $this->get('/inventory')->assertRedirect('/inventory/items');

        $this->get('/finance/approvals')->assertOk();
        $this->get('/finance/expenses')->assertOk();
        $this->get('/finance/overhead')->assertOk();
    }
}
