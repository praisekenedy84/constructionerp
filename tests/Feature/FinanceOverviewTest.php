<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_overview_is_available_to_finance_roles(): void
    {
        $tenant = Tenant::create(['name' => 'Finance Dash Co', 'slug' => 'finance-dash-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Finance Manager',
            'email' => 'finance@dash-co.local',
            'password' => 'password',
            'role' => 'Finance Manager',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'finance@dash-co.local',
            'password' => 'password',
        ]);

        $this->get('/finance')
            ->assertRedirect('/finance/overview');

        $this->get('/finance/overview')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Overview')
                ->has('summary.project_cash_on_hand')
                ->has('summary.organization_cash_on_hand')
                ->has('summary.pending_fund_count')
                ->has('summary.awaiting_fulfillment_count')
                ->has('fund_pipeline')
                ->has('pending_funds.data')
                ->has('awaiting_fulfillment.data')
                ->has('active_projects.data')
            );
    }
}
