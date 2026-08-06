<?php

namespace Tests\Feature;

use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Models\CashAllocation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseListingFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Expense List Co',
            'slug' => 'expense-list-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@expenselist.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'EL-001',
                'name' => 'Expense List Project',
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
        });

        tenancy()->end();

        return $tenant;
    }

    public function test_direct_expenses_page_includes_summary_and_filters(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-list-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '500000.00',
                'received_amount' => '500000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'requested_at' => now(),
                'received_at' => now(),
                'decided_at' => now(),
            ]);

            $requisition = Requisition::create([
                'requisition_no' => 'REQ-2026-09001',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'other',
                'requestor_id' => 1,
                'status' => 'fulfilled',
                'fulfillment_type' => 'cash_disbursement',
                'original_amount' => '100000.00',
            ]);

            Expense::create([
                'project_id' => 1,
                'requisition_id' => $requisition->id,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Fuel',
                'activity_ref' => $requisition->requisition_no,
                'amount' => '100000.00',
                'description' => 'Diesel from requisition',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);

            Expense::create([
                'project_id' => 1,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Administrative',
                'amount' => '25000.00',
                'description' => 'Manual site expense',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);

            Expense::create([
                'project_id' => 1,
                'valuation_id' => null,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Retention',
                'activity_ref' => 'IPC-1',
                'amount' => '50000.00',
                'description' => 'IPC-1 compliance — Retention',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);
        });

        // Link the third expense to a real valuation so source=ipc filtering works.
        Tenant::where('slug', 'expense-list-co')->first()->run(function () {
            $phase = \App\Models\ProjectPhase::create([
                'project_id' => 1,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'disbursed_amount' => '100000.00',
                'phase_net_budget' => '100000.00',
            ]);

            $valuation = \App\Models\Valuation::create([
                'project_id' => 1,
                'phase_id' => $phase->id,
                'certificate_no' => 1,
                'gross_value' => '0.00',
                'total_deductions' => '50000.00',
                'net_value' => '50000.00',
                'status' => 'draft',
                'created_by' => 1,
            ]);

            Expense::query()
                ->where('activity_ref', 'IPC-1')
                ->update(['valuation_id' => $valuation->id]);
        });

        $this->post('/login', [
            'email' => 'admin@expenselist.local',
            'password' => 'password',
        ]);

        $this->get('/finance/expenses')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Finance/Expenses')
            ->where('summary.total_amount', '175000.00')
            ->where('summary.from_requisitions', '100000.00')
            ->where('summary.from_ipcs', '50000.00')
            ->where('summary.manual_amount', '25000.00')
            ->where('summary.expense_count', 3)
            ->where('summary.ipc_count', 1)
            ->has('filterOptions.projects')
            ->has('filterOptions.sub_types')
        );

        $this->get('/finance/expenses?source=requisition')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.total_amount', '100000.00')
            ->where('summary.expense_count', 1)
        );

        $this->get('/finance/expenses?source=ipc')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.total_amount', '50000.00')
            ->where('summary.expense_count', 1)
        );

        $this->get('/finance/expenses?source=manual')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.total_amount', '25000.00')
            ->where('summary.expense_count', 1)
        );

        $this->get('/finance/expenses?sub_type=Fuel')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.total_amount', '100000.00')
            ->where('summary.expense_count', 1)
        );
    }

    public function test_direct_expenses_category_filter_matches_joined_labels(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-list-co')->first()->run(function () {
            Expense::create([
                'project_id' => 1,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Materials, Labor, Fuel, Equipment',
                'amount' => '7900000.00',
                'description' => 'Combined requisition categories',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);

            Expense::create([
                'project_id' => 1,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Retention',
                'amount' => '50000.00',
                'description' => 'IPC retention',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expenselist.local',
            'password' => 'password',
        ]);

        $this->get('/finance/expenses?sub_type=Materials')->assertOk()->assertInertia(fn ($page) => $page
            ->where('summary.total_amount', '7900000.00')
            ->where('summary.expense_count', 1)
            ->where('filterOptions.sub_types', fn ($types) => collect($types)->contains('Materials')
                && collect($types)->contains('Fuel')
                && collect($types)->contains('Retention'))
        );
    }

    public function test_direct_expenses_export_downloads_excel(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-list-co')->first()->run(function () {
            Expense::create([
                'project_id' => 1,
                'category' => ExpenseCategory::Direct,
                'sub_type' => 'Administrative',
                'amount' => '10000.00',
                'description' => 'Export me',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expenselist.local',
            'password' => 'password',
        ]);

        $this->get('/finance/expenses/export')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_overhead_page_includes_summary_and_export(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'expense-list-co')->first()->run(function () {
            Expense::create([
                'project_id' => null,
                'category' => ExpenseCategory::Indirect,
                'sub_type' => 'Utilities',
                'amount' => '40000.00',
                'description' => 'Office power',
                'expense_date' => now()->toDateString(),
                'recorded_by' => 1,
            ]);
        });

        $this->post('/login', [
            'email' => 'admin@expenselist.local',
            'password' => 'password',
        ]);

        $this->get('/finance/overhead')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Finance/Overhead')
            ->where('summary.total_amount', '40000.00')
            ->where('summary.manual_amount', '40000.00')
            ->has('filterOptions.sub_types')
        );

        $this->get('/finance/overhead/export')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
