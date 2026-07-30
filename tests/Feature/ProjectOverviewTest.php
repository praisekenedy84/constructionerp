<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Models\BudgetTransaction;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_overview_uses_ledger_remaining_budget_to_calculate_profit_percentage(): void
    {
        $tenant = Tenant::create([
            'name' => 'Project Overview Co',
            'slug' => 'project-overview-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@overview.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@overview.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $projectId = null;

        $tenant->run(function () use (&$projectId) {
            $project = Project::create([
                'code' => 'PRJ-OVERVIEW',
                'name' => 'Overview Project',
                'client' => 'Test Client',
                'location' => 'Mbeya',
                'contract_amount' => '1000.00',
                'wht_percentage' => '0',
                'net_budget' => '1000.00',
                'physical_progress_pct' => '25',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'status' => 'active',
            ]);

            BudgetTransaction::create([
                'project_id' => $project->id,
                'type' => BudgetTransactionType::CashAllocation,
                'amount' => '400.00',
                'reason' => 'Site allocation',
                'created_by' => User::where('email', 'admin@overview.local')->value('id'),
                'created_at' => now(),
            ]);

            $projectId = $project->id;
        });

        session(['current_project_id' => $projectId]);

        $this->get("/projects/{$projectId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Show')
                ->where('project.remaining_budget', '600.00')
                ->where('project.profit_percentage', '60.00')
            );

        $this->get('/projects')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Index')
                ->where('projects.data.0.profit_percentage', '60.00')
            );
    }
}
