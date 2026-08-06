<?php

namespace Tests\Feature;

use App\Enums\ComplianceAllocationLevel;
use App\Enums\ComplianceItemEventType;
use App\Models\ComplianceRule;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Models\Tenant;
use App\Models\Valuation;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectContractComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function loginAsTenantAdmin(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Compliance Co',
            'slug' => 'compliance-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Test Admin',
            'email' => 'admin@compliance.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@compliance.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        return $tenant;
    }

    private function createProject(string $code = 'PRJ-CMP'): int
    {
        $this->post('/projects', [
            'code' => $code,
            'name' => 'Compliance Project',
            'client' => 'Client',
            'client_phone' => '+255 700 000 001',
            'client_tin' => '100-100-100',
            'location' => 'Dar',
            'contract_amount' => '100000000',
            'wht_percentage' => '0',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $projectId = (int) Project::where('code', $code)->value('id');
        tenancy()->end();

        return $projectId;
    }

    private function createRules(): array
    {
        $this->post('/projects/compliance-rules', [
            'name' => 'Tax',
            'rule_type' => 'wht',
            'is_active' => true,
        ])->assertRedirect();

        $this->post('/projects/compliance-rules', [
            'name' => 'Insurance',
            'rule_type' => 'other',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $taxId = (int) ComplianceRule::where('name', 'Tax')->value('id');
        $insuranceId = (int) ComplianceRule::where('name', 'Insurance')->value('id');
        tenancy()->end();

        return [$taxId, $insuranceId];
    }

    public function test_project_net_budget_starts_as_full_contract_before_phases(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PRJ-FULL');

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $this->assertSame('100000000.00', (string) $project->net_budget);
        $this->assertSame(0, $project->phases()->count());
        tenancy()->end();
    }

    public function test_compliance_attaches_to_contract_without_phase(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject();
        [$taxId, $insuranceId] = $this->createRules();

        $this->post("/projects/{$projectId}/compliance", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $taxId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '5000000',
                ],
                [
                    'compliance_rule_id' => $insuranceId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '2000000',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $this->assertSame(2, ProjectComplianceItem::where('project_id', $projectId)->count());
        $this->assertSame(0, $project->phases()->count());
        $this->assertSame(0, Valuation::where('project_id', $projectId)->count());
        // 100M − 7M compliance = 93M remaining contract value
        $this->assertSame('93000000.00', (string) $project->net_budget);
        $this->assertDatabaseHas('project_compliance_items', [
            'project_id' => $projectId,
            'compliance_rule_id' => $taxId,
            'allocation_level' => ComplianceAllocationLevel::Contract->value,
            'amount' => '5000000.00',
        ]);
        $this->assertDatabaseHas('project_compliance_item_events', [
            'event_type' => ComplianceItemEventType::AttachedToContract->value,
        ]);
        tenancy()->end();
    }

    public function test_phase_one_migrates_contract_compliance_without_duplication(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PRJ-MIG');
        [$taxId, $insuranceId] = $this->createRules();

        $this->post("/projects/{$projectId}/compliance", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $taxId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '5000000',
                ],
                [
                    'compliance_rule_id' => $insuranceId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '2000000',
                ],
            ],
        ])->assertRedirect();

        $this->post("/projects/{$projectId}/phases", [
            'name' => 'Phase 1',
            'disbursed_amount' => '40000000',
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $phase = $project->phases()->firstOrFail();
        $items = ProjectComplianceItem::where('project_id', $projectId)->get();

        $this->assertSame(1, $project->phases()->count());
        $this->assertSame(2, $items->count());
        $this->assertTrue($items->every(
            fn (ProjectComplianceItem $item) => $item->allocation_level === ComplianceAllocationLevel::Phase
        ));
        $this->assertTrue($items->every(
            fn (ProjectComplianceItem $item) => (int) $item->phase_id === (int) $phase->id
        ));
        $this->assertSame(0, ProjectComplianceItem::query()->where('project_id', $projectId)->onContract()->count());

        $valuation = Valuation::where('project_id', $projectId)->where('phase_id', $phase->id)->firstOrFail();
        $this->assertSame(2, $valuation->deductions()->count());
        $this->assertSame('7000000.00', (string) $valuation->total_deductions);

        // Phase net = 40M − 7M = 33M
        $this->assertSame('33000000.00', (string) $phase->fresh()->phase_net_budget);
        $this->assertSame('33000000.00', (string) $project->fresh()->net_budget);

        $this->assertDatabaseHas('project_compliance_item_events', [
            'event_type' => ComplianceItemEventType::MigratedToPhase->value,
            'phase_id' => $phase->id,
            'valuation_id' => $valuation->id,
        ]);
        tenancy()->end();
    }

    public function test_rate_percent_compliance_uses_contract_value(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PRJ-RATE');

        $this->post('/projects/compliance-rules', [
            'name' => 'WHT 5%',
            'rule_type' => 'wht',
            'is_active' => true,
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $ruleId = (int) ComplianceRule::where('name', 'WHT 5%')->value('id');
        tenancy()->end();

        $this->post("/projects/{$projectId}/compliance", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $ruleId,
                    'calculation_type' => 'rate_percent',
                    'rate' => '5',
                ],
            ],
        ])->assertRedirect();

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $this->assertDatabaseHas('project_compliance_items', [
            'project_id' => $projectId,
            'amount' => '5000000.00',
        ]);
        $this->assertSame('95000000.00', (string) $project->net_budget);
        tenancy()->end();
    }

    public function test_cannot_attach_contract_compliance_after_phase_exists(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PRJ-BLOCK');
        [$taxId] = $this->createRules();

        $this->post("/projects/{$projectId}/phases", [
            'name' => 'Phase 1',
            'disbursed_amount' => '40000000',
        ])->assertRedirect();

        $this->from("/projects/{$projectId}")
            ->post("/projects/{$projectId}/compliance", [
                'compliance_items' => [
                    [
                        'compliance_rule_id' => $taxId,
                        'calculation_type' => 'fixed_amount',
                        'fixed_amount' => '5000000',
                    ],
                ],
            ])
            ->assertRedirect("/projects/{$projectId}")
            ->assertSessionHasErrors('compliance');

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $this->assertSame(0, ProjectComplianceItem::where('project_id', $projectId)->count());
        tenancy()->end();
    }

    public function test_phase_one_disbursement_must_cover_contract_compliance(): void
    {
        $this->loginAsTenantAdmin();
        $projectId = $this->createProject('PRJ-MIN');
        [$taxId, $insuranceId] = $this->createRules();

        $this->post("/projects/{$projectId}/compliance", [
            'compliance_items' => [
                [
                    'compliance_rule_id' => $taxId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '5000000',
                ],
                [
                    'compliance_rule_id' => $insuranceId,
                    'calculation_type' => 'fixed_amount',
                    'fixed_amount' => '2000000',
                ],
            ],
        ])->assertRedirect();

        $this->from("/projects/{$projectId}")
            ->post("/projects/{$projectId}/phases", [
                'name' => 'Phase 1',
                'disbursed_amount' => '6000000',
            ])
            ->assertRedirect("/projects/{$projectId}")
            ->assertSessionHasErrors('phase');

        tenancy()->initialize(Tenant::where('slug', 'compliance-co')->firstOrFail());
        $project = Project::findOrFail($projectId);
        $this->assertSame(0, $project->phases()->count());
        $this->assertSame(2, ProjectComplianceItem::query()->where('project_id', $projectId)->onContract()->count());
        $this->assertSame('93000000.00', (string) $project->net_budget);
        tenancy()->end();
    }
}
