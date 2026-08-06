<?php

namespace Tests\Feature;

use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Enums\PayrollRunStatus;
use App\Enums\PayStructure;
use App\Enums\RequisitionStatus;
use App\Models\BudgetTransaction;
use App\Models\CashDisbursement;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PayrollOverheadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{tenant: Tenant, admin: \App\Models\User, project: Project}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::create([
            'name' => 'Payroll Co',
            'slug' => 'payroll-co',
            'status' => 'active',
        ]);

        $admin = app(AuthService::class)->createUser($tenant, [
            'name' => 'Finance Admin',
            'email' => 'finance@payroll.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->initialize($tenant);

        $project = Project::create([
            'code' => 'PRJ-PAY',
            'name' => 'Payroll Site',
            'client' => 'Client',
            'location' => 'Dar',
            'contract_amount' => '1000000',
            'wht_percentage' => '5',
            'net_budget' => '950000',
            'physical_progress_pct' => '0',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        tenancy()->end();

        return compact('tenant', 'admin', 'project');
    }

    private function fundFinanceWallet(string $balance = '1000000.00'): void
    {
        app(MoneyAccountService::class)
            ->ensureFinanceAccount()
            ->update(['balance' => $balance]);
    }

    public function test_submitting_payroll_creates_requisition_and_fulfillment_posts_salaries_overhead(): void
    {
        ['project' => $project] = $this->setupTenant();

        $this->post('/login', [
            'email' => 'finance@payroll.local',
            'password' => 'password',
        ]);

        tenancy()->initialize(Tenant::where('slug', 'payroll-co')->firstOrFail());
        $this->fundFinanceWallet('1000000.00');

        $employee = Employee::create([
            'employee_no' => 'E-001',
            'name' => 'Worker One',
            'role' => 'Labourer',
            'pay_structure' => PayStructure::Monthly,
            'monthly_salary' => '500000',
            'project_id' => $project->id,
        ]);

        $run = PayrollRun::create([
            'project_id' => $project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollRunStatus::Draft,
        ]);

        PayrollItem::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base' => '500000',
            'overtime' => '0',
            'allowances' => '0',
            'deductions_total' => '0',
            'net_pay' => '500000',
            'created_at' => now(),
        ]);
        tenancy()->end();

        $this->post("/payroll/{$run->id}/post")
            ->assertRedirect(route('payroll.runs.show', $run->id));

        tenancy()->initialize(Tenant::where('slug', 'payroll-co')->firstOrFail());

        $run->refresh();
        $this->assertSame(PayrollRunStatus::Approved, $run->status);
        $this->assertNotNull($run->requisition_id);

        $requisition = Requisition::with('items', 'approvalSteps')->findOrFail($run->requisition_id);
        $this->assertNull($requisition->project_id);
        $this->assertSame(1, $requisition->items()->count());
        $this->assertSame('Worker One', $requisition->items->first()->recipient_name);
        $this->assertSame(0, Expense::count());

        // Seeded workflow may leave the req under review — resolve approval when needed.
        if ($requisition->status === RequisitionStatus::UnderReview) {
            $step = $requisition->approvalSteps()->where('status', 'pending')->first();
            $this->assertNotNull($step);
            $this->post("/approvals/steps/{$step->id}/resolve", [
                'action' => 'approved',
            ])->assertRedirect();
            $requisition->refresh();
        }

        $this->assertSame(RequisitionStatus::Approved, $requisition->status);

        $this->post("/requisitions/{$requisition->id}/transition", [
            'to_status' => 'fulfilled',
            'payee' => 'Payroll',
            'account_name' => 'Payroll',
            'account_number' => '255700000001',
            'payment_date' => now()->toDateString(),
            'reference_no' => 'PAY-1',
            'method' => 'bank',
        ])->assertRedirect();

        $run->refresh();
        $this->assertSame(PayrollRunStatus::Posted, $run->status);

        $this->assertDatabaseHas('expenses', [
            'category' => ExpenseCategory::Indirect->value,
            'sub_type' => 'Salaries',
            'amount' => '500000.00',
            'activity_ref' => "payroll_run:{$run->id}",
        ]);

        $this->assertSame(0, BudgetTransaction::where('type', BudgetTransactionType::Payroll)->count());
        $this->assertSame(1, CashDisbursement::count());

        $this->get('/finance/overhead')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Overhead')
                ->has('expenses.data', 1)
                ->where('expenses.data.0.sub_type', 'Salaries')
                ->where('total_overhead', '500000.00')
            );

        tenancy()->end();
    }

    public function test_submitting_payroll_requires_organization_cash(): void
    {
        ['project' => $project] = $this->setupTenant();

        $this->post('/login', [
            'email' => 'finance@payroll.local',
            'password' => 'password',
        ]);

        tenancy()->initialize(Tenant::where('slug', 'payroll-co')->firstOrFail());
        $this->fundFinanceWallet('0.00');

        $employee = Employee::create([
            'employee_no' => 'E-010',
            'name' => 'Worker Ten',
            'role' => 'Labourer',
            'pay_structure' => PayStructure::Monthly,
            'monthly_salary' => '100000',
            'project_id' => $project->id,
        ]);

        $run = PayrollRun::create([
            'project_id' => $project->id,
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'status' => PayrollRunStatus::Draft,
        ]);

        PayrollItem::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base' => '100000',
            'overtime' => '0',
            'allowances' => '0',
            'deductions_total' => '0',
            'net_pay' => '100000',
            'created_at' => now(),
        ]);
        tenancy()->end();

        $this->post("/payroll/{$run->id}/post")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(PayrollRunStatus::Draft, $run->fresh()->status);
        $this->assertSame(0, Expense::count());
        $this->assertSame(0, Requisition::count());
    }

    public function test_overhead_page_backfills_legacy_payroll_budget_posts(): void
    {
        ['admin' => $admin, 'project' => $project] = $this->setupTenant();

        $this->post('/login', [
            'email' => 'finance@payroll.local',
            'password' => 'password',
        ]);

        $employee = Employee::create([
            'employee_no' => 'E-002',
            'name' => 'Worker Two',
            'role' => 'Foreman',
            'pay_structure' => PayStructure::Monthly,
            'monthly_salary' => '800000',
            'project_id' => $project->id,
        ]);

        $run = PayrollRun::create([
            'project_id' => $project->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => PayrollRunStatus::Posted,
        ]);

        $item = PayrollItem::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base' => '800000',
            'overtime' => '0',
            'allowances' => '0',
            'deductions_total' => '0',
            'net_pay' => '800000',
            'created_at' => now(),
        ]);

        BudgetTransaction::create([
            'project_id' => $project->id,
            'type' => BudgetTransactionType::Payroll,
            'amount' => '800000',
            'reference_entity_type' => 'payroll_item',
            'reference_entity_id' => $item->id,
            'created_by' => $admin->id,
            'created_at' => now(),
        ]);

        $this->assertSame(0, Expense::where('sub_type', 'Salaries')->count());

        $this->get('/finance/overhead')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/Overhead')
                ->has('expenses.data', 1)
                ->where('expenses.data.0.sub_type', 'Salaries')
                ->where('total_overhead', '800000.00')
            );

        $this->assertSame(0, BudgetTransaction::where('type', BudgetTransactionType::Payroll)->count());
        $this->assertTrue(
            Expense::query()
                ->where('activity_ref', "payroll_run:{$run->id}")
                ->where('category', ExpenseCategory::Indirect)
                ->exists()
        );

        $this->get('/finance/overhead')->assertOk();
        $this->assertSame(1, Expense::where('sub_type', 'Salaries')->count());
    }

    public function test_backfill_service_is_idempotent(): void
    {
        ['admin' => $admin, 'project' => $project] = $this->setupTenant();

        tenancy()->initialize(Tenant::where('slug', 'payroll-co')->firstOrFail());

        $employee = Employee::create([
            'employee_no' => 'E-003',
            'name' => 'Worker Three',
            'role' => 'Clerk',
            'pay_structure' => PayStructure::Monthly,
            'monthly_salary' => '300000',
            'project_id' => $project->id,
        ]);

        $run = PayrollRun::create([
            'project_id' => $project->id,
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'status' => PayrollRunStatus::Posted,
        ]);

        PayrollItem::create([
            'payroll_run_id' => $run->id,
            'employee_id' => $employee->id,
            'base' => '300000',
            'overtime' => '0',
            'allowances' => '0',
            'deductions_total' => '0',
            'net_pay' => '300000',
            'created_at' => now(),
        ]);

        $service = app(PayrollService::class);
        $this->assertSame(1, $service->backfillLegacyPayrollOverhead($admin));
        $this->assertSame(0, $service->backfillLegacyPayrollOverhead($admin));
        $this->assertSame(1, Expense::where('activity_ref', "payroll_run:{$run->id}")->count());

        tenancy()->end();
    }
}
