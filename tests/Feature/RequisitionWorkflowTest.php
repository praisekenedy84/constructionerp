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
                'resource_type' => 'materials',
                'requestor_id' => 2,
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => 'stock_issue',
                'addressed_to' => 'storekeeper',
                'original_amount' => '50000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $requisition->id,
                'boq_item_id' => $boqItem->id,
                'description' => 'Excavation works',
                'unit' => 'm3',
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

    public function test_system_administrator_can_publish_another_users_draft(): void
    {
        $this->seedTenantWithUsers();

        $this->post('/login', [
            'email' => 'admin@workflow.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'under_review',
        ])->assertRedirect();

        Tenant::where('slug', 'workflow-co')->first()->run(function () {
            $req = Requisition::first();
            $this->assertSame('under_review', $req->status->value);
            $this->assertNotSame(1, (int) $req->requestor_id);
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

    public function test_finance_manager_can_amend_with_line_items(): void
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
        $itemId = null;
        Tenant::where('slug', 'workflow-co')->first()->run(function () use (&$stepId, &$itemId) {
            $stepId = ApprovalStep::first()->id;
            $itemId = RequisitionItem::first()->id;
        });

        $this->post("/approvals/steps/{$stepId}/resolve", [
            'action' => 'amended',
            'amendment_reason' => 'Reduce scope after site check',
            'items' => [
                [
                    'id' => $itemId,
                    'description' => 'Excavation',
                    'unit' => 'm3',
                    'quantity' => '5',
                    'unit_cost' => '5000',
                ],
            ],
        ])->assertRedirect();

        Tenant::where('slug', 'workflow-co')->first()->run(function () {
            $req = Requisition::with('items', 'statusHistories')->first();
            $item = $req->items->first();

            $this->assertSame('amended', $req->status->value);
            $this->assertSame('25000.00', (string) $req->amended_amount);
            $this->assertSame('50000.00', (string) $req->original_amount);
            $this->assertSame('5.000', (string) $item->quantity);
            $this->assertSame('10.000', (string) $item->original_quantity);
            $this->assertSame('50000.00', (string) $item->original_line_total);

            $history = $req->statusHistories->firstWhere('to_status', RequisitionStatus::Amended);
            $this->assertNotNull($history);
            $this->assertSame('25000.00', (string) $history->amended_amount);
            $this->assertSame('25000.00', (string) $history->variance);
            $this->assertIsArray($history->amendment_items);
            $this->assertCount(1, $history->amendment_items['before']);
            $this->assertCount(1, $history->amendment_items['after']);
        });
    }

    public function test_fulfill_can_create_new_inventory_item(): void
    {
        $this->seedTenantWithUsers();

        $locationId = null;
        Tenant::where('slug', 'workflow-co')->first()->run(function () use (&$locationId) {
            $req = Requisition::first();
            $req->update(['status' => RequisitionStatus::Approved]);

            $location = \App\Models\StockLocation::create([
                'name' => 'Site Store',
                'project_id' => $req->project_id,
            ]);
            $locationId = $location->id;
        });

        $this->post('/login', [
            'email' => 'admin@workflow.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'inventory_source' => 'new',
            'stock_location_id' => $locationId,
            'new_inventory_item' => [
                'name' => 'Site Aggregate',
                'unit' => 'm3',
                'category' => 'materials',
                'unit_cost' => '5000',
            ],
        ])->assertRedirect();

        Tenant::where('slug', 'workflow-co')->first()->run(function () {
            $req = Requisition::with('items')->first();
            $item = \App\Models\InventoryItem::where('name', 'Site Aggregate')->first();

            $this->assertSame('fulfilled', $req->status->value);
            $this->assertNotNull($item);
            $this->assertSame($item->id, $req->items->first()->inventory_item_id);
            $this->assertSame(
                '0.000',
                (string) \App\Models\StockBalance::where('inventory_item_id', $item->id)->value('quantity_on_hand')
            );
            $this->assertDatabaseHas('inventory_issues', [
                'requisition_id' => $req->id,
                'inventory_item_id' => $item->id,
            ]);
            $this->assertDatabaseHas('expenses', [
                'requisition_id' => $req->id,
                'category' => 'direct',
                'project_id' => $req->project_id,
            ]);
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

    public function test_approver_cannot_see_draft_until_published(): void
    {
        $this->seedTenantWithUsers();

        $this->post('/login', [
            'email' => 'finance@workflow.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->get('/requisitions/1')->assertForbidden();

        $this->get('/requisitions')->assertOk();
        $this->get('/requisitions')->assertInertia(fn ($page) => $page
            ->component('Requisitions/Index')
            ->has('rows.data', 0)
        );

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'engineer@workflow.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->get('/requisitions/1')->assertOk();
        $this->post('/requisitions/1/transition', [
            'to_status' => 'under_review',
        ])->assertRedirect();

        $this->post('/logout');

        $this->post('/login', [
            'email' => 'finance@workflow.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->get('/requisitions/1')->assertOk();
        $this->get('/requisitions/review-queue')->assertOk();
        $this->get('/requisitions/review-queue')->assertInertia(fn ($page) => $page
            ->component('Requisitions/Review')
            ->has('approvalSteps.data', 1)
            ->has('cashByRequisitionId')
        );
    }
}
