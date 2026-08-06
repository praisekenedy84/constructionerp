<?php

namespace Tests\Feature;

use App\Enums\ExpenseCategory;
use App\Enums\RequisitionStatus;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Recipient;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
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

            Recipient::create([
                'name' => 'Alice Worker',
                'phone' => '+255700000001',
                'status' => 'active',
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
                    'recipient_id' => 1,
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
            app(MoneyAccountService::class)->ensureFinanceAccount()->update(['balance' => '200000.00']);

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
            'account_name' => 'Supplier A',
            'account_number' => '255700000001',
            'payment_date' => now()->toDateString(),
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
            $this->assertNull($disbursement->cash_allocation_id);
            $this->assertNotNull($disbursement->money_account_id);
            $this->assertSame('160000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_organization_fulfillment_records_overhead_expense(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () {
            app(MoneyAccountService::class)->ensureFinanceAccount()->update(['balance' => '300000.00']);

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
            'account_name' => 'Utility Co',
            'account_number' => '255700000002',
            'payment_date' => now()->toDateString(),
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

            $disbursement = CashDisbursement::first();
            $this->assertSame($expense->id, $disbursement->expense_id);
            $this->assertNull($disbursement->cash_allocation_id);
            $this->assertNotNull($disbursement->money_account_id);
            $this->assertSame('245000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_fulfillment_creates_one_disbursement_per_recipient(): void
    {
        $this->seedTenant();

        $requisitionId = null;
        $aliceId = null;
        $bobId = null;

        Tenant::where('slug', 'req-expense-co')->first()->run(function () use (&$requisitionId, &$aliceId, &$bobId) {
            app(MoneyAccountService::class)->ensureFinanceAccount()->update(['balance' => '500000.00']);

            $alice = Recipient::where('name', 'Alice Worker')->firstOrFail();
            $bob = Recipient::create([
                'name' => 'Bob Helper',
                'phone' => '+255700000002',
                'status' => 'active',
            ]);
            $aliceId = $alice->id;
            $bobId = $bob->id;

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-01003',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'other',
                'requestor_id' => 2,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '30000.00',
            ]);
            $requisitionId = $req->id;

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Alice wages',
                'unit' => 'day',
                'quantity' => '1.000',
                'unit_cost' => '10000.00',
                'line_total' => '10000.00',
                'recipient_id' => $alice->id,
                'recipient_name' => 'Alice Worker',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Bob wages',
                'unit' => 'day',
                'quantity' => '1.000',
                'unit_cost' => '20000.00',
                'line_total' => '20000.00',
                'recipient_id' => $bob->id,
                'recipient_name' => 'Bob Helper',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@reqexpense.local',
            'password' => 'password',
        ]);

        $this->post("/requisitions/{$requisitionId}/transition", [
            'to_status' => 'fulfilled',
            'fulfillment_scope' => 'items',
            'payments' => [
                [
                    'recipient_key' => 'id:'.$aliceId,
                    'recipient_id' => $aliceId,
                    'payee' => 'Alice Worker',
                    'account_name' => 'Alice Worker',
                    'account_number' => '255711111111',
                    'payment_date' => now()->toDateString(),
                    'reference_no' => 'RCP-ALICE-1',
                    'method' => 'mobile',
                ],
                [
                    'recipient_key' => 'id:'.$bobId,
                    'recipient_id' => $bobId,
                    'payee' => 'Bob Helper',
                    'account_name' => 'Bob Helper',
                    'account_number' => '255722222222',
                    'payment_date' => now()->toDateString(),
                    'reference_no' => 'RCP-BOB-1',
                    'method' => 'cash',
                ],
            ],
        ])->assertRedirect();

        Tenant::where('slug', 'req-expense-co')->first()->run(function () use ($aliceId, $bobId, $requisitionId) {
            $disbursements = CashDisbursement::query()->orderBy('id')->get();
            $this->assertCount(2, $disbursements);
            $this->assertSame($aliceId, (int) $disbursements[0]->recipient_id);
            $this->assertSame('10000.00', (string) $disbursements[0]->amount);
            $this->assertSame($bobId, (int) $disbursements[1]->recipient_id);
            $this->assertSame('20000.00', (string) $disbursements[1]->amount);

            $this->assertSame(2, Expense::count());
            $req = Requisition::findOrFail($requisitionId);
            $this->assertSame(RequisitionStatus::Fulfilled, $req->status);
            $this->assertSame('30000.00', (string) $req->fulfilled_amount);
            $this->assertSame('470000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }
}
