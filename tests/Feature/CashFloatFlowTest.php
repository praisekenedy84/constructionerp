<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\MoneyAccountType;
use App\Enums\RequisitionStatus;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\MoneyAccount;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowConfig;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PermissionService;
use App\Services\RequisitionService;
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

    private function managerAccountId(): int
    {
        return (int) MoneyAccount::query()
            ->where('type', MoneyAccountType::Manager)
            ->orderBy('id')
            ->value('id');
    }

    public function test_manager_approve_transfers_to_finance_without_budget_hit(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'manager@cashfloat.local',
            'password' => 'password',
        ]);

        $accountId = null;
        Tenant::where('slug', 'cash-float-co')->first()->run(function () use (&$accountId) {
            $accountId = $this->managerAccountId();
            app(MoneyAccountService::class)->deposit(
                MoneyAccount::findOrFail($accountId),
                '500000',
                User::where('email', 'manager@cashfloat.local')->first(),
            );
        });

        auth()->logout();

        $this->post('/login', [
            'email' => 'finance@cashfloat.local',
            'password' => 'password',
        ]);

        $this->post('/finance/cash-requests', [
            'requested_amount' => '500000',
        ])->assertRedirect();

        auth()->logout();

        $this->post('/login', [
            'email' => 'manager@cashfloat.local',
            'password' => 'password',
        ]);

        $this->post('/finance/cash-requests/1/approve', [
            'source_account_id' => $accountId,
            'approved_amount' => '400000',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () use ($accountId) {
            $allocation = CashAllocation::first();
            $this->assertSame(CashAllocationStatus::Received, $allocation->status);
            $this->assertSame('400000.00', (string) $allocation->received_amount);
            $this->assertSame($accountId, (int) $allocation->source_account_id);

            $this->assertNull(
                BudgetTransaction::where('type', BudgetTransactionType::CashAllocation)->first(),
                'Fund approval must not charge project budget'
            );

            $this->assertSame('100000.00', (string) MoneyAccount::find($accountId)->balance);
            $this->assertSame('400000.00', app(MoneyAccountService::class)->financeBalance());
        });
    }

    public function test_cash_fulfillment_requires_receipt_and_deducts_finance_wallet(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '200000.00']);

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
            'account_name' => 'Site Foreman Account',
            'account_number' => '255700000001',
            'payment_date' => now()->toDateString(),
            'reference_no' => 'RCP-001',
            'method' => 'mobile',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $this->assertSame('150000.00', app(MoneyAccountService::class)->financeBalance());

            $disbursement = CashDisbursement::first();
            $this->assertSame('Site Foreman Account', $disbursement->payee);
            $this->assertSame('RCP-001', $disbursement->reference_no);
            $this->assertSame('mobile', $disbursement->method);
            $this->assertNotNull($disbursement->money_account_id);
            $this->assertNotNull($disbursement->account_transaction_id);
        });
    }

    public function test_cash_requisition_can_be_fulfilled_in_multiple_installments(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            app(MoneyAccountService::class)->ensureFinanceAccount()->update(['balance' => '100000.00']);

            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-PARTIAL-CASH',
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
                'description' => 'Site operations',
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
            'fulfillment_scope' => 'whole',
            'amount' => '20000',
            'payee' => 'Site Foreman',
            'account_name' => 'Site Foreman',
            'account_number' => '255700000001',
            'payment_date' => now()->toDateString(),
            'reference_no' => 'PART-001',
            'method' => 'cash',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $req = Requisition::first();
            $this->assertSame(RequisitionStatus::PartiallyFulfilled, $req->status);
            $this->assertSame('20000.00', (string) $req->fulfilled_amount);
            $this->assertSame('80000.00', app(MoneyAccountService::class)->financeBalance());
        });

        $this->post('/requisitions/1/transition', [
            'to_status' => 'fulfilled',
            'fulfillment_scope' => 'whole',
            'amount' => '30000',
            'payee' => 'Site Foreman',
            'account_name' => 'Site Foreman',
            'account_number' => '255700000001',
            'payment_date' => now()->toDateString(),
            'reference_no' => 'PART-002',
            'method' => 'bank',
        ])->assertRedirect();

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $req = Requisition::with('items')->first();
            $this->assertSame(RequisitionStatus::Fulfilled, $req->status);
            $this->assertSame('50000.00', (string) $req->fulfilled_amount);
            $this->assertSame('1.000', (string) $req->items->first()->fulfilled_quantity);
            $this->assertSame(2, CashDisbursement::count());
            $this->assertSame(2, Expense::where('requisition_id', $req->id)->count());
            $this->assertSame('50000.00', app(MoneyAccountService::class)->financeBalance());
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
            $finance = app(MoneyAccountService::class)->ensureFinanceAccount();
            $finance->update(['balance' => '100000.00']);

            Requisition::create([
                'requisition_no' => 'REQ-2026-00101',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '80000.00',
                'addressed_to' => 'finance',
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@cashfloat.local',
            'password' => 'password',
        ]);

        Tenant::where('slug', 'cash-float-co')->first()->run(function () {
            $req = Requisition::create([
                'requisition_no' => 'REQ-2026-00102',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'cash',
                'requestor_id' => 4,
                'status' => RequisitionStatus::Submitted,
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '30000.00',
                'addressed_to' => 'finance',
            ]);

            RequisitionItem::create([
                'requisition_id' => $req->id,
                'description' => 'Extra',
                'unit' => 'lump',
                'quantity' => '1.000',
                'unit_cost' => '30000.00',
                'line_total' => '30000.00',
            ]);

            $availability = app(RequisitionService::class)
                ->cashAvailability($req, '30000.00');

            $this->assertTrue($availability['exceeds']);
            $this->assertSame('20000.00', $availability['available']);
        });
    }
}
