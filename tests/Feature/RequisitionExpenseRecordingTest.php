<?php

namespace Tests\Feature;

use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Enums\RequisitionStatus;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionExpenseRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Req Expense Co',
            'slug' => 'req-expense-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@reqexpense.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);
        $auth->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@reqexpense.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'RE-001',
                'name' => 'Expense Project',
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

    public function test_can_create_organization_wide_requisition(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@reqexpense.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => null,
            'department' => 'Administration',
            'resource_type' => 'other',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Office stationery',
                    'unit' => 'lump',
                    'quantity' => '1',
                    'unit_cost' => '75000',
                ],
            ],
        ])->assertRedirect();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            $req = Requisition::first();
            $this->assertNotNull($req);
            $this->assertNull($req->project_id);
            $this->assertTrue($req->isOrganizationWide());
            $this->assertSame('75000.00', (string) $req->original_amount);
        });
    }

    public function test_project_fulfillment_records_direct_expense(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '200000.00',
                'received_amount' => '200000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-01001',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'other',
                'requestor_id' => 2,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '40000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Site materials',
                'unit' => 'lump',
                'quantity' => '1.000',
                'unit_cost' => '40000.00',
                'line_total' => '40000.00',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@reqexpense.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'payee' => 'Supplier A',
            'reference_no' => 'RCP-DIR-1',
            'method' => 'cash',
        ])->assertRedirect();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            $expense = Expense::first();
            $this->assertNotNull($expense);
            $this->assertSame(ExpenseCategory::Direct, $expense->category);
            $this->assertSame(1, (int) $expense->project_id);
            $this->assertSame(1, (int) $expense->requisition_id);
            $this->assertSame('40000.00', (string) $expense->amount);

            $disbursement = CashDisbursement::first();
            $this->assertSame($expense->id, $disbursement->expense_id);
            $this->assertSame(1, (int) $disbursement->requisition_id);
        });
    }

    public function test_organization_fulfillment_records_overhead_expense(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
                'requested_amount' => '300000.00',
                'received_amount' => '300000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-01002',
                'project_id' => null,
                'department' => 'Administration',
                'resource_type' => 'other',
                'requestor_id' => 2,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '55000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Head office utilities',
                'unit' => 'lump',
                'quantity' => '1.000',
                'unit_cost' => '55000.00',
                'line_total' => '55000.00',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@reqexpense.local',
            'password' => 'password',
        ]);

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'payee' => 'Utility Co',
            'reference_no' => 'RCP-OH-1',
            'method' => 'bank',
        ])->assertRedirect();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            $expense = Expense::first();
            $this->assertNotNull($expense);
            $this->assertSame(ExpenseCategory::Indirect, $expense->category);
            $this->assertNull($expense->project_id);
            $this->assertSame(1, (int) $expense->requisition_id);
            $this->assertSame('55000.00', (string) $expense->amount);

            $allocation = CashAllocation::first();
            $this->assertSame('55000.00', (string) $allocation->utilized_amount);

            $disbursement = CashDisbursement::first();
            $this->assertSame($expense->id, $disbursement->expense_id);
            $this->assertTrue($disbursement->cashAllocation->isOrganizationWide());
        });
    }
}
