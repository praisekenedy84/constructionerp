<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\RequisitionStatus;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashFloatFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Cash Float Co',
            'slug' => 'cash-float-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@cashfloat.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);
        $auth->createUser($tenant, [
            'name' => 'Finance',
            'email' => 'finance@cashfloat.local',
            'password' => 'password',
            'role' => 'Finance Manager',
        ]);
        $auth->createUser($tenant, [
            'name' => 'Manager',
            'email' => 'manager@cashfloat.local',
            'password' => 'password',
            'role' => 'Manager',
        ]);
        $auth->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@cashfloat.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'CF-001',
                'name' => 'Cash Float Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'net_budget' => '9500000.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);

            WorkflowConfig::query()->delete();
            WorkflowConfig::create([
                'project_id' => null,
                'level' => 1,
                'role_name' => 'Finance Manager',
                'threshold_min' => '0.00',
                'threshold_max' => null,
            ]);
        });

        tenancy()->end();

        return $tenant;
    }

    public function test_manager_approve_funds_cash_and_deducts_budget(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'finance@cashfloat.local',
            'password' => 'password',
        ]);

        $this->post('/finance/cash-requests', [
            'project_id' => 1,
            'requested_amount' => '500000',
        ])->assertRedirect();

        auth()->logout();

        $this->post('/login', [
            'email' => 'manager@cashfloat.local',
            'password' => 'password',
        ]);

        $this->post('/finance/cash-requests/1/approve', [
            'approved_amount' => '400000',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $allocation = CashAllocation::first();
            $this->assertSame(CashAllocationStatus::Received, $allocation->status);
            $this->assertSame('400000.00', (string) $allocation->received_amount);
            $this->assertSame('400000.00', (string) $allocation->requested_amount);

            $txn = BudgetTransaction::where('type', BudgetTransactionType::CashAllocation)->first();
            $this->assertNotNull($txn);
            $this->assertSame('400000.00', (string) $txn->amount);
        });
    }

    public function test_cash_fulfillment_requires_receipt_and_deducts_cash_on_hand(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '200000.00',
                'received_amount' => '200000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 2,
                'approved_by' => 3,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-00099',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '50000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Petty cash',
                'unit' => 'lump',
                'quantity' => '1.000',
                'unit_cost' => '50000.00',
                'line_total' => '50000.00',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@cashfloat.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'method' => 'cash',
        ])->assertSessionHasErrors();

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'payee' => 'Site Foreman Account',
            'reference_no' => 'RCP-001',
            'method' => 'mobile',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $allocation = CashAllocation::first();
            $this->assertSame('50000.00', (string) $allocation->utilized_amount);
            $this->assertSame('150000.00', (string) $allocation->balance);

            $disbursement = CashDisbursement::first();
            $this->assertSame('Site Foreman Account', $disbursement->payee);
            $this->assertSame('RCP-001', $disbursement->reference_no);
            $this->assertSame('mobile', $disbursement->method);
        });
    }

    public function test_approved_requisition_can_be_deleted(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            Requisition::create([
                'requisition_no' => 'REQ-2026-00100',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '10000.00',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@cashfloat.local',
            'password' => 'password',
        ]);

        $this->delete('/requisitions/1')->assertRedirect('/requisitions');

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $this->assertNull(Requisition::find(1));
            $this->assertNotNull(Requisition::withTrashed()->find(1));
        });
    }

    public function test_cannot_over_approve_beyond_uncommitted_cash(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '100000.00',
                'received_amount' => '100000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 2,
                'approved_by' => 3,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            // Already approved — commits 80k of the 100k float.
            Requisition::create([
                'requisition_no' => 'REQ-2026-00101',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '80000.00',
            ]);

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-00102',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::UnderReview,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '50000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'More cash',
                'unit' => 'lump',
                'quantity' => '1.000',
                'unit_cost' => '50000.00',
                'line_total' => '50000.00',
            ]);

            \App\Models\ApprovalStep::create([
                'requisition_id' => $req->id,
                'level' => 1,
                'required_role' => 'Finance Manager',
                'status' => 'pending',
                'assigned_at' => now(),
            ]);
        });

        $this->post('/login', [
            'email' => 'finance@cashfloat.local',
            'password' => 'password',
        ]);

        $stepId = null;
        Tenant::where('slug', 'cash-float-co')->first()->run(function () use (&$stepId) {
            $stepId = \App\Models\ApprovalStep::where('status', 'pending')->value('id');
        });

        $this->post("/approvals/steps/{$stepId}/resolve", [
            'action' => 'approved',
        ])->assertRedirect();

        // Flash error expected — only 20k uncommitted cash remains.
        $this->assertTrue(
            session()->has('error') || session()->has('errors'),
            'Approving beyond uncommitted cash should fail'
        );
        $this->assertStringContainsString(
            'Amend the requisition down to available cash, or reject it',
            (string) session('error'),
        );

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $this->assertSame(
                RequisitionStatus::UnderReview,
                Requisition::where('requisition_no', 'REQ-2026-00102')->first()->status
            );
        });

        $this->get('/requisitions/review-queue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Review')
                ->where('cashByRequisitionId', function ($cash) {
                    $rows = collect($cash);
                    if ($rows->isEmpty()) {
                        return false;
                    }

                    $entry = $rows->first(fn ($row) => ($row['exceeds'] ?? false) === true);

                    return $entry
                        && (float) $entry['available'] === 20000.0
                        && (float) $entry['required'] === 50000.0;
                })
            );
    }
}
