<?php

namespace Tests\Feature;

use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\ComplianceRule;
use App\Models\Expense;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Tenant;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use App\Services\AuthService;
use App\Services\BudgetService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValuationIpcComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsTenantAdmin(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'IPC Co',
            'slug' => 'ipc-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@ipc.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@ipc.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    private function createPhase(int $projectId, string $amount, string $name = 'Phase 1'): int
    {
        $this->post("/projects/{$projectId}/phases", [
            'name' => $name,
            'disbursed_amount' => $amount,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $id = (int) ProjectPhase::where('project_id', $projectId)->where('name', $name)->value('id');
        tenancy()->end();

        return $id;
    }

    private function createProject(string $code = 'IPC-PRJ', string $contract = '1000000'): int
    {
        $this->post('/projects', [
            'code' => $code,
            'name' => 'IPC Project',
            'client' => 'Client',
            'client_phone' => '+255 700 000 002',
            'client_tin' => '200-200-200',
            'location' => 'Dar',
            'contract_amount' => $contract,
            'wht_percentage' => '5',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $id = (int) Project::where('code', $code)->value('id');
        tenancy()->end();

        return $id;
    }

    /**
     * @return array{retention: int, material: int, advance: int, wht: int, site: int}
     */
    private function createRules(): array
    {
        // Retention is seeded for every tenant via migration.
        $this->post('/projects/compliance-rules', [
            'name' => 'Material test fee',
            'rule_type' => 'material_test',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Advance recovery',
            'rule_type' => 'advance_recovery',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'WHT',
            'rule_type' => 'wht',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Site lab',
            'rule_type' => 'other',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $ids = [
            'retention' => (int) ComplianceRule::where('name', 'Retention')->value('id'),
            'material' => (int) ComplianceRule::where('name', 'Material test fee')->value('id'),
            'advance' => (int) ComplianceRule::where('name', 'Advance recovery')->value('id'),
            'wht' => (int) ComplianceRule::where('name', 'WHT')->value('id'),
            'site' => (int) ComplianceRule::where('name', 'Site lab')->value('id'),
        ];
        tenancy()->end();

        return $ids;
    }

    public function test_retention_compliance_rule_is_seeded_for_tenant(): void
    {
        $this->loginAsTenantAdmin();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertDatabaseHas('compliance_rules', [
            'name' => 'Retention',
            'rule_type' => 'retention',
            'is_active' => 1,
            'deleted_at' => null,
        ]);
    }

    public function test_compliance_rule_catalog_can_be_created(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects/compliance-rules', [
            'name' => 'Material test fee',
            'description' => 'Lab testing deduction',
            'rule_type' => 'material_test',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertDatabaseHas('compliance_rules', [
            'name' => 'Material test fee',
            'rule_type' => 'material_test',
            'is_active' => 1,
        ]);
    }

    public function test_ipc_stores_user_filled_rate_and_fixed_compliance_and_reduces_net_budget(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject();
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                    'fixed_amount' => null,
                ],
                [
                    'compliance_rule_id' => $rules['material'],
                    'calculation_type' => 'fixed_amount',
                    'rate' => null,
                    'fixed_amount' => '5000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());

        $valuation = Valuation::where('project_id', $projectId)->firstOrFail();
        $this->assertSame(1, $valuation->certificate_no);
        $this->assertSame('105000.00', (string) $valuation->total_deductions);
        $this->assertCount(2, $valuation->deductions);

        $retention = ValuationDeduction::where('valuation_id', $valuation->id)
            ->where('name', 'Retention')
            ->firstOrFail();
        $this->assertSame($rules['retention'], $retention->compliance_rule_id);
        $this->assertSame('rate_percent', $retention->calculation_type->value);
        $this->assertSame('100000.00', (string) $retention->amount);

        $project = Project::findOrFail($projectId);
        $this->assertSame('895000.00', (string) $project->net_budget);

        $expenses = Expense::query()
            ->where('valuation_id', $valuation->id)
            ->where('category', 'direct')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $expenses);
        $this->assertSame('100000.00', (string) $expenses[0]->amount);
        $this->assertSame('Retention', $expenses[0]->sub_type);
        $this->assertSame('IPC-1', $expenses[0]->activity_ref);
        $this->assertSame('5000.00', (string) $expenses[1]->amount);
        $this->assertSame('Material test fee', $expenses[1]->sub_type);
        $this->assertEmpty($expenses[0]->cashDisbursements);
    }

    public function test_multiple_ipcs_accumulate_compliance_against_contract(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('MULTI-IPC', '500000');
        $phaseId = $this->createPhase($projectId, '500000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['advance'],
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '15000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());

        $this->assertSame(2, Valuation::where('project_id', $projectId)->count());
        $project = Project::findOrFail($projectId);
        $this->assertSame('435000.00', (string) $project->net_budget);
    }

    public function test_ipc_compliance_is_deducted_from_remaining_budget_only_once(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('NET-ONCE', '100000000');
        $phaseId = $this->createPhase($projectId, '50000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
                [
                    'compliance_rule_id' => $rules['material'],
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '3550000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());

        // 50,000,000 phase − (5,000,000 retention + 3,550,000 fees) = 41,450,000
        $project = Project::findOrFail($projectId);
        $budget = app(BudgetService::class)->summary($project);
        $this->assertSame('41450000.00', (string) $project->net_budget);
        $this->assertSame('41450000.00', $budget['remaining_budget']);
        $this->assertSame('8550000.00', $budget['ipc_deductions']);
        $this->assertSame('8550000.00', $budget['utilized_budget']);
        $this->assertSame('17.10', $budget['utilization_percentage']);
        $this->assertSame(0, BudgetTransaction::where('project_id', $projectId)->count());

        $report = app(ReportService::class)->executiveDashboard(['project_id' => $projectId]);
        $this->assertSame('8550000.00', $report['projects'][0]['spent']);
        $this->assertSame('17.10', $report['projects'][0]['utilization_pct']);
    }

    public function test_editing_a_draft_ipc_does_not_accumulate_budget_charges(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('NO-DRIFT', '1000000');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [[
                'compliance_rule_id' => $rules['retention'],
                'calculation_type' => 'rate_percent',
                'rate' => '10',
            ]],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $valuationId = (int) Valuation::where('project_id', $projectId)->value('id');
        tenancy()->end();

        for ($i = 0; $i < 3; $i++) {
            $this->put("/projects/{$projectId}/valuations/{$valuationId}", [
                'phase_id' => $phaseId,
                'compliance_items' => [[
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ]],
            ])->assertRedirect("/projects/{$projectId}/valuations/{$valuationId}");
        }

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $this->assertSame('900000.00', (string) $project->net_budget);
        $this->assertSame('900000.00', app(BudgetService::class)->remainingBudget($project));
    }

    public function test_rate_compliance_requires_positive_rate(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('FAIL-IPC');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->from("/projects/{$projectId}/valuations/create")
            ->post("/projects/{$projectId}/valuations", [
                'phase_id' => $phaseId,
                'compliance_items' => [
                    [
                        'compliance_rule_id' => $rules['retention'],
                        'calculation_type' => 'rate_percent',
                        'rate' => '',
                    ],
                ],
            ])
            ->assertRedirect("/projects/{$projectId}/valuations/create")
            ->assertSessionHasErrors('compliance_items.0.rate');
    }

    public function test_draft_ipc_can_be_updated(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('EDIT-IPC');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $valuationId = (int) Valuation::where('project_id', $projectId)->value('id');
        tenancy()->end();

        $this->put("/projects/{$projectId}/valuations/{$valuationId}", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['wht'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '5',
                ],
                [
                    'compliance_rule_id' => $rules['site'],
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '2000',
                ],
            ],
        ])->assertRedirect("/projects/{$projectId}/valuations/{$valuationId}");

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $valuation = Valuation::findOrFail($valuationId);
        $this->assertSame('52000.00', (string) $valuation->total_deductions);
        $this->assertCount(2, $valuation->deductions);
        $this->assertSame('948000.00', (string) Project::findOrFail($projectId)->net_budget);

        $expenses = Expense::query()->where('valuation_id', $valuationId)->orderBy('id')->get();
        $this->assertCount(2, $expenses);
        $this->assertSame(['WHT', 'Site lab'], $expenses->pluck('sub_type')->all());
        $this->assertSame('50000.00', (string) $expenses[0]->amount);
        $this->assertSame('2000.00', (string) $expenses[1]->amount);
    }

    public function test_draft_ipc_can_be_deleted_and_net_budget_restored(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('DEL-IPC');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['material'],
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '5000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertSame('895000.00', (string) Project::findOrFail($projectId)->net_budget);
        $firstId = (int) Valuation::where('project_id', $projectId)->where('certificate_no', 1)->value('id');
        tenancy()->end();

        $this->delete("/projects/{$projectId}/valuations/{$firstId}")
            ->assertRedirect("/projects/{$projectId}/valuations");

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertSoftDeleted('valuations', ['id' => $firstId]);
        $this->assertSame(1, Valuation::where('project_id', $projectId)->count());
        $this->assertSame('995000.00', (string) Project::findOrFail($projectId)->net_budget);
        $this->assertSame(0, Expense::where('valuation_id', $firstId)->count());
        $this->assertSoftDeleted('expenses', ['valuation_id' => $firstId, 'activity_ref' => 'IPC-1']);
    }

    public function test_certified_ipc_cannot_be_deleted(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('CERT-DEL');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $valuation = Valuation::where('project_id', $projectId)->firstOrFail();
        $valuationId = $valuation->id;
        tenancy()->end();

        $this->post("/valuations/{$valuationId}/certify")->assertRedirect();

        $this->from("/projects/{$projectId}/valuations/{$valuationId}")
            ->delete("/projects/{$projectId}/valuations/{$valuationId}")
            ->assertRedirect("/projects/{$projectId}/valuations/{$valuationId}")
            ->assertSessionHasErrors('status');

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertDatabaseHas('valuations', ['id' => $valuationId, 'deleted_at' => null]);
        $this->assertSame('900000.00', (string) Project::findOrFail($projectId)->net_budget);
    }

    public function test_releasing_retention_adds_held_amount_back_to_budget(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('REL-RET');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [[
                'compliance_rule_id' => $rules['retention'],
                'calculation_type' => 'rate_percent',
                'rate' => '10',
            ]],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/phases/{$phaseId}/retention/release")
            ->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::findOrFail($phaseId);
        $this->assertSame('1000000.00', (string) $project->net_budget);
        $this->assertSame('0.00', (string) $phase->retention_held_amount);
        $this->assertSame('100000.00', (string) $phase->retention_released_amount);
    }

    public function test_forfeiting_retention_keeps_budget_reduced(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('FOR-RET');
        $phaseId = $this->createPhase($projectId, '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [[
                'compliance_rule_id' => $rules['retention'],
                'calculation_type' => 'rate_percent',
                'rate' => '10',
            ]],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/phases/{$phaseId}/retention/forfeit")
            ->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $phase = ProjectPhase::findOrFail($phaseId);
        $this->assertSame('900000.00', (string) $project->net_budget);
        $this->assertSame('0.00', (string) $phase->retention_held_amount);
        $this->assertSame('100000.00', (string) $phase->retention_forfeited_amount);
    }

    public function test_adding_phase_can_include_ipcs_for_that_phase(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PHASE-IPCS', '1000000');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/phases", [
            'name' => 'Phase 2 Batch',
            'disbursed_amount' => '300000',
            'ipcs' => [
                [
                    'compliance_items' => [
                        [
                            'compliance_rule_id' => $rules['retention'],
                            'calculation_type' => 'rate_percent',
                            'rate' => '10',
                        ],
                    ],
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $phase = ProjectPhase::where('project_id', $projectId)->where('name', 'Phase 2 Batch')->firstOrFail();
        $this->assertSame('300000.00', (string) $phase->disbursed_amount);
        $this->assertSame(1, Valuation::where('phase_id', $phase->id)->count());
        // 300,000 − 10% retention = 270,000
        $this->assertSame('270000.00', (string) Project::findOrFail($projectId)->net_budget);
    }

    public function test_finance_approval_is_blocked_when_phase_budget_not_yet_disbursed(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('NO-DISB-PHASE');

        $this->post('/finance/cash-requests', [
            'project_id' => $projectId,
            'requested_amount' => '20000',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $allocationId = (int) CashAllocation::where('project_id', $projectId)->latest('id')->value('id');
        tenancy()->end();

        $this->post("/finance/cash-requests/{$allocationId}/approve", [
            'approved_amount' => '20000',
        ])->assertRedirect()->assertSessionHas('error');
    }

    public function test_phase_show_includes_ipc_breakdown(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PHASE-SHOW');
        $phaseId = $this->createPhase($projectId, '500000', 'Mobilisation');
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'phase_id' => $phaseId,
            'compliance_items' => [[
                'compliance_rule_id' => $rules['retention'],
                'calculation_type' => 'rate_percent',
                'rate' => '10',
            ]],
        ])->assertRedirect();

        $this->get("/projects/{$projectId}/phases/{$phaseId}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Projects/Phases/Show')
                ->where('phase.id', $phaseId)
                ->where('phase.name', 'Mobilisation')
                ->where('phase.disbursed_amount', '500000.00')
                ->where('summary.phase_compliance_total', '50000.00')
                ->has('valuations', 1)
                ->where('valuations.0.certificate_no', 1)
            );
    }
}
