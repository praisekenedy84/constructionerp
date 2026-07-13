<?php

namespace Tests\Feature;

use App\Enums\RequisitionStatus;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenantWithUsers(): array
    {
        $tenant = Tenant::create([
            'name' => 'Workflow Co',
            'slug' => 'workflow-co',
        ]);

        $auth = app(AuthService::class);

        $admin = $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@workflow.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $engineer = $auth->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@workflow.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $financeManager = $auth->createUser($tenant, [
            'name' => 'Finance Manager',
            'email' => 'finance@workflow.local',
            'password' => 'password',
            'role' => 'Finance Manager',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            $project = Project::create([
                'code' => 'WF-001',
                'name' => 'Workflow Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);

            $section = BoqSection::create([
                'project_id' => $project->id,
                'name' => 'Earthworks',
                'display_order' => 1,
            ]);

            $boqItem = BoqItem::create([
                'section_id' => $section->id,
                'description' => 'Excavation',
                'unit' => 'm3',
                'category' => 'materials',
                'budgeted_qty' => '1000.0000',
                'unit_rate' => '5000.00',
                'budgeted_amount' => '5000000.00',
                'reserved_qty' => '0.0000',
                'consumed_qty' => '0.0000',
            ]);

            WorkflowConfig::query()->delete();
            WorkflowConfig::create([
                'project_id' => null,
                'level' => 1,
                'role_name' => 'Finance Manager',
                'threshold_min' => '0.00',
                'threshold_max' => null,
            ]);

            $requisition = Requisition::create([
                'requisition_no' => 'REQ-2026-00001',
                'project_id' => $project->id,
                'boq_item_id' => $boqItem->id,
                'department' => 'Site',
                'requestor_id' => 2,
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => 'stock_issue',
                'original_amount' => '50000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'boq_item_id' => $boqItem->id,
                'description' => 'Excavation works',
                'quantity' => '10.0000',
                'unit_cost' => '5000.00',
                'line_total' => '50000.00',
            ]);
        });

        tenancy()->end();

        return compact('tenant', 'admin', 'engineer', 'financeManager');
    }

    public function test_submit_creates_approval_step(): void
    {
        $this->seedTenantWithUsers();

        $this->post('/login', [
            'email' => 'engineer@workflow.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'under_review',
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'workflow-co')->first();
        $tenant->run(function () {
            $req = Requisition::first();
            $this->assertSame('under_review', $req->status->value);
            $this->assertSame(1, ApprovalStep::where('requisition_id', $req->id)->where('status', 'pending')->count());
        });
    }

    public function test_finance_manager_can_resolve_approval_step(): void
    {
        $this->seedTenantWithUsers();

        $this->post('/login', [
            'email' => 'engineer@workflow.local',
            'password' => 'password',
        ]);
        $this->post('/requisitions/1/transition', ['to_status' => 'under_review']);

        auth()->logout();

        $this->post('/login', [
            'email' => 'finance@workflow.local',
            'password' => 'password',
        ]);

        $stepId = null;
        Tenant::where('slug', 'workflow-co')->first()->run(function () use (&$stepId) {
            $stepId = ApprovalStep::first()->id;
        });

        $this->post("/approvals/steps/{$stepId}/resolve", [
            'action' => 'approved',
            'comment' => 'Looks good',
        ])->assertRedirect();

        Tenant::where('slug', 'workflow-co')->first()->run(function () {
            $req = Requisition::first();
            $this->assertSame('approved', $req->status->value);
        });
    }

    public function test_direct_approve_transition_is_blocked(): void
    {
        $this->seedTenantWithUsers();

        $this->post('/login', [
            'email' => 'finance@workflow.local',
            'password' => 'password',
        ]);

        Tenant::where('slug', 'workflow-co')->first()->run(function () {
            Requisition::first()->update(['status' => RequisitionStatus::UnderReview]);
        });

        $this->post('/requisitions/1/transition', [
            'to_status' => 'approved',
        ])->assertSessionHasErrors();
    }
}
