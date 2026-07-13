<?php

namespace Tests\Feature;

use App\Models\CashAllocation;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\CashAllocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FundApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private function setupTenantWithAllocation(): array
    {
        $tenant = Tenant::create([
            'name' => 'Fund Co',
            'slug' => 'fund-co',
            'status' => 'active',
        ]);

        $auth = app(AuthService::class);

        $admin = $auth->createUser($tenant, [
            'name' => 'Finance Admin',
            'email' => 'finance@fund.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->initialize($tenant);

        $project = Project::create([
            'code' => 'PRJ-001',
            'name' => 'Main Site',
            'client' => 'Client',
            'location' => 'Dar',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'net_budget' => '950000',
            'physical_progress_pct' => '0',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        $allocation = app(CashAllocationService::class)->request(
            $project->id,
            '50000',
            $admin,
        );

        tenancy()->end();

        return compact('tenant', 'admin', 'project', 'allocation');
    }

    public function test_fund_approvals_lists_all_statuses(): void
    {
        $this->setupTenantWithAllocation();

        $this->post('/login', [
            'email' => 'finance@fund.local',
            'password' => 'password',
        ]);

        $this->get('/finance/approvals')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/FundApprovals')
                ->has('allocations.data', 1)
                ->has('summary.total')
            );
    }

    public function test_fund_approvals_export_excel(): void
    {
        $this->setupTenantWithAllocation();

        $this->post('/login', [
            'email' => 'finance@fund.local',
            'password' => 'password',
        ]);

        $this->get('/finance/approvals/export?format=xlsx')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
