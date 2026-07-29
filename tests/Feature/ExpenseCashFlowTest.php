<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseCashFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Expense Cash Co',
            'slug' => 'expense-cash-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@expensecash.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'EX-001',
                'name' => 'Expense Cash Project',
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

            Project::create([
                'code' => 'EX-002',
                'name' => 'Other Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '5000000.00',
                'wht_percentage' => '5.00',
                'net_budget' => '4750000.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);
        });

        tenancy()->end();

        return $tenant;
    }

    public function test_direct_expense_reduces_selected_cash_float(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '200000.00',
                'received_amount' => '200000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'reference_no' => 'FLOAT-1',
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            // Already charged when the float was funded — must not be charged again.
            BudgetTransaction::create([
                'project_id' => 1,
                'type' => BudgetTransactionType::CashAllocation,
                'amount' => '200000.00',
                'reference_entity_type' => 'cash_allocation',
                'reference_entity_id' => 1,
                'created_by' => 1,
                'created_at' => now(),
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'cash_allocation_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Transport',
            'amount' => '75000',
            'description' => 'Site transport',
            'expense_date' => now()->toDateString(),
            'payee' => 'Driver',
            'reference_no' => 'RCP-EXP-1',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $allocation = CashAllocation::find(1);
            $this->assertSame('75000.00', (string) $allocation->utilized_amount);
            $this->assertSame('125000.00', (string) $allocation->balance);

            $expense = Expense::first();
            $this->assertSame(ExpenseCategory::Direct, $expense->category);
            $this->assertSame('75000.00', (string) $expense->amount);

            $disbursement = CashDisbursement::first();
            $this->assertSame($expense->id, $disbursement->expense_id);
            $this->assertNull($disbursement->requisition_id);
            $this->assertSame('Driver', $disbursement->payee);
            $this->assertSame('RCP-EXP-1', $disbursement->reference_no);

            $this->assertNull(
                BudgetTransaction::where('type', BudgetTransactionType::DirectExpense)->first(),
                'Project float was already budgeted at funding — do not double-charge'
            );

            $position = app(ReportService::class)->cashPosition(['project_id' => 1]);
            $this->assertSame('125000.00', $position['cash_on_hand']);
            $this->assertSame('75000.00', $position['disbursed']);
        });
    }

    public function test_cannot_expense_above_selected_float_balance(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '50000.00',
                'received_amount' => '50000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'cash_allocation_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '60000',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertTrue(
            session()->has('errors'),
            'Spending above the selected float balance should fail'
        );

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame(0, CashDisbursement::count());
            $this->assertSame('0.00', (string) CashAllocation::first()->utilized_amount);
        });
    }

    public function test_cannot_spend_another_projects_float(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 2,
                'requested_amount' => '100000.00',
                'received_amount' => '100000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'cash_allocation_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '10000',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertTrue(session()->has('errors'));

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
        });
    }

    public function test_org_wide_float_expense_posts_direct_budget_transaction(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
                'requested_amount' => '300000.00',
                'received_amount' => '300000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'reference_no' => 'ORG-FLOAT',
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'cash_allocation_id' => 1,
            'method' => 'mobile',
            'sub_type' => 'Materials',
            'amount' => '40000',
            'expense_date' => now()->toDateString(),
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $allocation = CashAllocation::first();
            $this->assertSame('40000.00', (string) $allocation->utilized_amount);

            $txn = BudgetTransaction::where('type', BudgetTransactionType::DirectExpense)->first();
            $this->assertNotNull($txn);
            $this->assertSame('40000.00', (string) $txn->amount);
            $this->assertSame(1, $txn->project_id);
        });
    }

    public function test_indirect_expense_uses_organisation_cash_float(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
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
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '150000',
            'expense_date' => now()->toDateString(),
            'description' => 'Office rent',
            'cash_allocation_id' => 1,
            'method' => 'bank',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $expense = Expense::first();
            $this->assertSame(ExpenseCategory::Indirect, $expense->category);
            $this->assertNull($expense->project_id);
            $this->assertSame(1, CashDisbursement::count());
            $this->assertSame('150000.00', (string) CashAllocation::first()->utilized_amount);
        });
    }

    public function test_editing_and_deleting_direct_expense_adjusts_cash_on_hand(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
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
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $payload = [
            'category' => 'direct',
            'project_id' => 1,
            'cash_allocation_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '75000',
            'expense_date' => now()->toDateString(),
        ];

        $this->post('/finance/expenses', $payload)->assertRedirect();
        $this->put('/finance/expenses/1', [
            ...$payload,
            'amount' => '100000',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame('100000.00', (string) Expense::first()->amount);
            $this->assertSame('100000.00', (string) CashAllocation::first()->utilized_amount);
            $this->assertSame('100000.00', (string) CashAllocation::first()->balance);
        });

        $this->delete('/finance/expenses/1')->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertNull(Expense::find(1));
            $this->assertNotNull(Expense::withTrashed()->find(1));
            $this->assertSame('0.00', (string) CashAllocation::first()->utilized_amount);
            $this->assertSame('200000.00', (string) CashAllocation::first()->balance);
            $this->assertSame(0, CashDisbursement::count());
        });
    }

    public function test_editing_and_deleting_indirect_expense_adjusts_cash_on_hand(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
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
        });

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $payload = [
            'category' => 'indirect',
            'cash_allocation_id' => 1,
            'method' => 'bank',
            'sub_type' => 'Rent',
            'amount' => '150000',
            'expense_date' => now()->toDateString(),
        ];

        $this->post('/finance/expenses', $payload)->assertRedirect();
        $this->put('/finance/expenses/1', [
            ...$payload,
            'amount' => '90000',
        ])->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertSame('90000.00', (string) Expense::first()->amount);
            $this->assertSame('90000.00', (string) CashAllocation::first()->utilized_amount);
            $this->assertSame('210000.00', (string) CashAllocation::first()->balance);
        });

        $this->delete('/finance/expenses/1')->assertRedirect();

        Tenant::where('slug', 'expense-cash-co')->first()->run(function () {
            $this->assertNull(Expense::find(1));
            $this->assertSame('0.00', (string) CashAllocation::first()->utilized_amount);
            $this->assertSame('300000.00', (string) CashAllocation::first()->balance);
        });
    }

    public function test_direct_expense_requires_cash_float(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@expensecash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'direct',
            'project_id' => 1,
            'method' => 'cash',
            'sub_type' => 'Fuel',
            'amount' => '10000',
            'expense_date' => now()->toDateString(),
        ])->assertSessionHasErrors(['cash_allocation_id']);
    }
}
