<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectComplianceRule;
use App\Models\Tenant;
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

    public function test_project_can_be_created_with_inactive_empty_compliance_rules(): void
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
            'compliance_rules' => [
                [
                    'rule_type' => 'retention',
                    'rate' => '',
                    'is_active' => false,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'advance_recovery',
                    'rate' => '',
                    'is_active' => false,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'wht',
                    'rate' => '5',
                    'is_active' => true,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'defect_liability',
                    'rate' => '',
                    'is_active' => false,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'material_test',
                    'rate' => '',
                    'is_active' => false,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'hiv_report',
                    'rate' => '',
                    'is_active' => false,
                    'max_amount' => '',
                ],
            ],
        ]);

        $projectId = null;
        $tenant->run(function () use (&$projectId) {
            $project = Project::where('code', 'PRJ-100')->first();
            $this->assertNotNull($project);
            $this->assertSame('950000.00', (string) $project->net_budget);
            $this->assertCount(1, $project->complianceRules);
            $this->assertSame('wht', $project->complianceRules->first()->rule_type->value);
            $projectId = $project->id;
        });

        $response->assertRedirect("/projects/{$projectId}");
    }

    public function test_project_create_persists_active_compliance_rules_with_max_amount(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects', [
            'code' => 'PRJ-200',
            'name' => 'Bridge Works',
            'client' => 'Client A',
            'location' => 'Arusha',
            'contract_amount' => '2000000',
            'wht_percentage' => '5',
            'start_date' => '2026-02-01',
            'end_date' => '2027-02-01',
            'compliance_rules' => [
                [
                    'rule_type' => 'retention',
                    'rate' => '10',
                    'is_active' => true,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'advance_recovery',
                    'rate' => '15',
                    'is_active' => true,
                    'max_amount' => '100000',
                ],
                [
                    'rule_type' => 'wht',
                    'rate' => '5',
                    'is_active' => true,
                    'max_amount' => '',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());

        $project = Project::where('code', 'PRJ-200')->firstOrFail();
        $this->assertCount(3, ProjectComplianceRule::where('project_id', $project->id)->get());
        $this->assertSame('100000.00', (string) $project->complianceRules()->where('rule_type', 'advance_recovery')->value('max_amount'));
        // 2,000,000 - retention 200,000 - advance 100,000 - WHT 100,000 = 1,600,000
        $this->assertSame('1600000.00', (string) $project->net_budget);
    }

    public function test_active_compliance_rule_requires_rate_or_fixed_amount(): void
    {
        $this->loginAsTenantAdmin();

        $this->from('/projects/create')
            ->post('/projects', [
                'code' => 'PRJ-300',
                'name' => 'Failed Project',
                'client' => 'Client B',
                'location' => 'Mwanza',
                'contract_amount' => '500000',
                'wht_percentage' => '5',
                'start_date' => '2026-03-01',
                'end_date' => '2026-09-01',
                'compliance_rules' => [
                    [
                        'rule_type' => 'retention',
                        'rate' => '',
                        'is_active' => true,
                        'max_amount' => '',
                    ],
                ],
            ])
            ->assertRedirect('/projects/create')
            ->assertSessionHasErrors('compliance_rules.0');
    }

    public function test_active_compliance_rule_can_use_fixed_amount_only(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects', [
            'code' => 'PRJ-400',
            'name' => 'Fixed Charge Project',
            'client' => 'Client C',
            'location' => 'Dodoma',
            'contract_amount' => '1000000',
            'wht_percentage' => '0',
            'start_date' => '2026-04-01',
            'end_date' => '2026-10-01',
            'compliance_rules' => [
                [
                    'rule_type' => 'material_test',
                    'rate' => '',
                    'is_active' => true,
                    'max_amount' => '25000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());

        $project = Project::where('code', 'PRJ-400')->firstOrFail();
        $rule = $project->complianceRules()->where('rule_type', 'material_test')->firstOrFail();

        $this->assertSame('0.00', (string) $rule->rate);
        $this->assertSame('25000.00', (string) $rule->max_amount);
        $this->assertSame('975000.00', (string) $project->net_budget);
    }

    public function test_net_budget_subtracts_all_fixed_compliance_charges(): void
    {
        $this->loginAsTenantAdmin();

        $fixed = '5000000';
        $rules = [
            'retention',
            'advance_recovery',
            'wht',
            'defect_liability',
            'material_test',
            'hiv_report',
        ];

        $this->post('/projects', [
            'code' => 'PRJ-500',
            'name' => 'Full Charge Project',
            'client' => 'Client D',
            'location' => 'Mbeya',
            'contract_amount' => '100000000',
            'wht_percentage' => '5',
            'start_date' => '2026-05-01',
            'end_date' => '2027-05-01',
            'compliance_rules' => array_map(fn (string $type) => [
                'rule_type' => $type,
                'rate' => '',
                'is_active' => true,
                'max_amount' => $fixed,
            ], $rules),
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());

        $project = Project::where('code', 'PRJ-500')->firstOrFail();

        // 100,000,000 - (6 × 5,000,000) = 70,000,000
        $this->assertSame('70000000.00', (string) $project->net_budget);
        $this->assertCount(6, $project->complianceRules);
    }

    public function test_project_can_be_updated_and_net_budget_recalculated(): void
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
            'compliance_rules' => [
                [
                    'rule_type' => 'wht',
                    'rate' => '5',
                    'is_active' => true,
                    'max_amount' => '',
                ],
            ],
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
            'compliance_rules' => [
                [
                    'rule_type' => 'wht',
                    'rate' => '5',
                    'is_active' => true,
                    'max_amount' => '',
                ],
                [
                    'rule_type' => 'retention',
                    'rate' => '10',
                    'is_active' => true,
                    'max_amount' => '',
                ],
            ],
        ])->assertRedirect("/projects/{$projectId}");

        tenancy()->initialize(Tenant::where('slug', 'project-co')->firstOrFail());
        $project = Project::findOrFail($projectId);

        $this->assertSame('Updated Name', $project->name);
        $this->assertSame('active', $project->status->value);
        // 2,000,000 - WHT 100,000 - retention 200,000 = 1,700,000
        $this->assertSame('1700000.00', (string) $project->net_budget);
        $this->assertCount(2, $project->complianceRules);
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
            'compliance_rules' => [],
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
