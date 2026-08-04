<?php

namespace Tests\Feature;

use App\Models\ComplianceRule;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Valuation;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectCreateTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsTenantAdmin(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Project Co',
            'slug' => 'project-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@project.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@project.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    public function test_project_starts_with_zero_net_budget_before_phase_disbursement(): void
    {
        $tenant = $this->loginAsTenantAdmin();

        $response = $this->post('/projects', [
            'code' => 'PRJ-100',
            'name' => 'Highway Upgrade',
            'client' => 'TANROADS',
            'location' => 'Dar es Salaam',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = Project::where('code', 'PRJ-100')->first();
            $this->assertNotNull($project);
            $this->assertSame('0.00', (string) $project->net_budget);
            $this->assertSame('5.00', (string) $project->wht_percentage);
            $projectId = $project->id;
        });

        $response->assertRedirect("/projects/{$projectId}");
    }

    public function test_project_can_be_created_with_ipcs_on_same_form(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects/compliance-rules', [
            'name' => 'Retention',
            'rule_type' => 'retention',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Material test',
            'rule_type' => 'material_test',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $retentionId = (int) ComplianceRule::where('name', 'Retention')->value('id');
        $materialId = (int) ComplianceRule::where('name', 'Material test')->value('id');
        tenancy()->end();

        $this->post('/projects', [
            'code' => 'PRJ-IPC',
            'name' => 'With IPCs',
            'client' => 'Client',
            'location' => 'Dar',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'initial_phase_name' => 'Phase 1',
            'initial_phase_disbursed_amount' => '200000',
            'ipcs' => [
                [
                    'compliance_items' => [
                        [
                            'compliance_rule_id' => $retentionId,
                            'calculation_type' => 'rate_percent',
                            'rate' => '10',
                        ],
                    ],
                ],
                [
                    'compliance_items' => [
                        [
                            'compliance_rule_id' => $materialId,
                            'calculation_type' => 'fixed_amount',
                            'fixed_amount' => '5000',
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $project = Project::where('code', 'PRJ-IPC')->firstOrFail();
        $this->assertSame(2, Valuation::where('project_id', $project->id)->count());
        // Phase disbursed 200,000 − retention 20,000 − material 5,000 = 175,000
        $this->assertSame('175000.00', (string) $project->net_budget);
        $this->assertDatabaseHas('project_phases', [
            'project_id' => $project->id,
            'name' => 'Phase 1',
            'disbursed_amount' => '200000.00',
        ]);
    }

    public function test_project_update_keeps_net_budget_phase_driven(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects', [
            'code' => 'PRJ-600',
            'name' => 'Original Name',
            'client' => 'Client E',
            'location' => 'Morogoro',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-01',
            'status' => 'planning',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $project = Project::where('code', 'PRJ-600')->firstOrFail();
        $projectId = $project->id;
        tenancy()->end();

        $this->put("/projects/{$projectId}", [
            'code' => 'PRJ-600',
            'name' => 'Updated Name',
            'client' => 'Client E',
            'location' => 'Morogoro',
            'contract_amount' => '2000000',
            'wht_percentage' => '5',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-01',
            'status' => 'active',
        ])->assertRedirect("/projects/{$projectId}");

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $project = Project::findOrFail($projectId);

        $this->assertSame('Updated Name', $project->name);
        $this->assertSame('active', $project->status->value);
        $this->assertSame('0.00', (string) $project->net_budget);
    }

    public function test_project_can_be_soft_deleted(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects', [
            'code' => 'PRJ-700',
            'name' => 'Archive Me',
            'client' => 'Client F',
            'location' => 'Tanga',
            'contract_amount' => '500000',
            'wht_percentage' => '0',
            'start_date' => '2026-07-01',
            'end_date' => '2026-12-01',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $projectId = Project::where('code', 'PRJ-700')->value('id');
        tenancy()->end();

        $this->delete("/projects/{$projectId}")
            ->assertRedirect('/projects');

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $this->assertSoftDeleted('projects', ['id' => $projectId]);
    }
}
