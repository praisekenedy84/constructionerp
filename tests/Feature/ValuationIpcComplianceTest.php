<?php

namespace Tests\Feature;

use App\Models\ComplianceRule;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use App\Services\AuthService;
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

    private function createProject(string $code = 'IPC-PRJ', string $contract = '1000000'): int
    {
        $this->post('/projects', [
            'code' => $code,
            'name' => 'IPC Project',
            'client' => 'Client',
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
        $this->post('/projects/compliance-rules', [
            'name' => 'Retention',
            'description' => 'Retention deduction',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Material test fee',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Advance recovery',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'WHT',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Site lab',
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

    public function test_compliance_rule_catalog_can_be_created(): void
    {
        $this->loginAsTenantAdmin();

        $this->post('/projects/compliance-rules', [
            'name' => 'Retention',
            'description' => 'Standard retention',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'ipc-co')->firstOrFail());
        $this->assertDatabaseHas('compliance_rules', ['name' => 'Retention', 'is_active' => 1]);
    }

    public function test_ipc_stores_user_filled_rate_and_fixed_compliance_and_reduces_net_budget(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject();
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
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
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/valuations", [
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

    public function test_rate_compliance_requires_positive_rate(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('FAIL-IPC');
        $rules = $this->createRules();

        $this->from("/projects/{$projectId}/valuations/create")
            ->post("/projects/{$projectId}/valuations", [
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
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
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
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $rules['retention'],
                    'calculation_type' => 'rate_percent',
                    'rate' => '10',
                ],
            ],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/valuations", [
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
        $rules = $this->createRules();

        $this->post("/projects/{$projectId}/valuations", [
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
}
