<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

        $queryCount = 0;
        $captureQueries = false;
        DB::listen(function () use (&$queryCount, &$captureQueries) {
            if ($captureQueries) {
                $queryCount++;
            }
        });
        $measure = function (callable $callback) use (&$queryCount, &$captureQueries): int {
            $queryCount = 0;
            $captureQueries = true;
            try {
                $callback();
            } finally {
                $captureQueries = false;
            }

            return $queryCount;
        };

        $emptyPortfolioQueries = $measure(fn () => $this->get('/finance/overview')->assertOk());

        $tenant->run(function () {
            foreach (range(1, 6) as $number) {
                Project::create([
                    'code' => "FIN-{$number}",
                    'name' => "Finance Project {$number}",
                    'client' => 'Client',
                    'location' => 'Site',
                    'contract_amount' => '100000.00',
                    'wht_percentage' => '0',
                    'net_budget' => '100000.00',
                    'physical_progress_pct' => '0',
                    'start_date' => '2026-01-01',
                    'end_date' => '2026-12-31',
                    'status' => 'active',
                ]);
            }
        });

        $portfolioQueries = $measure(fn () => $this->get('/finance/overview')->assertOk());

        $this->assertLessThanOrEqual(
            $emptyPortfolioQueries + 4,
            $portfolioQueries,
            'Finance overview may add the fixed portfolio aggregates, but not per-project queries.',
        );
    }
}
