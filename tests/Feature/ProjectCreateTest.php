<?php

namespace Tests\Feature;

use App\Models\ComplianceRule;
use App\Models\Customer;
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

    public function test_project_starts_with_full_contract_net_budget_before_phase_disbursement(): void
    {
        $tenant = $this->loginAsTenantAdmin();

        $response = $this->post('/projects', [
            'code' => 'PRJ-100',
            'name' => 'Highway Upgrade',
            'client' => 'TANROADS',
            'client_phone' => '+255 700 111 222',
            'client_tin' => '100-200-300',
            'location' => 'Dar es Salaam',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = Project::where('code', 'PRJ-100')->with('customer')->first();
            $this->assertNotNull($project);
            // Before phases/compliance: remaining contract value equals contract amount.
            $this->assertSame('1000000.00', (string) $project->net_budget);
            $this->assertSame('5.00', (string) $project->wht_percentage);
            $this->assertNotNull($project->customer);
            $this->assertSame('+255 700 111 222', $project->customer->contact);
            $this->assertSame('100-200-300', $project->customer->tax_information);
            $this->assertSame('Dar es Salaam', $project->customer->address);
            $projectId = $project->id;
        });

        $response->assertRedirect("/projects/{$projectId}");
    }

    public function test_project_requires_client_phone_tin_and_location(): void
    {
        $this->loginAsTenantAdmin();

        $this->from('/projects/create')
            ->post('/projects', [
                'code' => 'PRJ-REQ',
                'name' => 'Missing Client Details',
                'client' => 'Acme Corp',
                'contract_amount' => '1000000',
                'wht_percentage' => '0',
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
            ])
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors(['client_phone', 'client_tin', 'location']);
    }

    public function test_project_can_be_created_with_ipcs_on_same_form(): void
    {
        $this->loginAsTenantAdmin();

        // Retention is seeded for every tenant via migration.
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
            'client_phone' => '+255 711 000 000',
            'client_tin' => '111-222-333',
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
            'client_phone' => '+255 722 000 000',
            'client_tin' => '400-500-600',
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
            'client_phone' => '+255 722 111 111',
            'client_tin' => '400-500-600',
            'location' => 'Morogoro',
            'contract_amount' => '2000000',
            'wht_percentage' => '5',
            'start_date' => '2026-06-01',
            'end_date' => '2026-12-01',
            'status' => 'active',
        ])->assertRedirect("/projects/{$projectId}");

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $project = Project::with('customer')->findOrFail($projectId);

        $this->assertSame('Updated Name', $project->name);
        $this->assertSame('active', $project->status->value);
        $this->assertSame('+255 722 111 111', $project->customer?->contact);
        // Still no phases: net tracks remaining contract value after update.
        $this->assertSame('2000000.00', (string) $project->net_budget);
        $this->assertSame(1, Customer::where('name', 'Client E')->count());
    }

    public function test_project_can_be_soft_deleted(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects', [
            'code' => 'PRJ-700',
            'name' => 'Archive Me',
            'client' => 'Client F',
            'client_phone' => '+255 733 000 000',
            'client_tin' => '700-800-900',
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
