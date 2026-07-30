<?php

namespace Tests\Feature;

use App\Enums\CashAllocationStatus;
use App\Enums\ExpenseCategory;
use App\Models\CashAllocation;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationCashTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create([
            'name' => 'Org Cash Co',
            'slug' => 'org-cash-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@orgcash.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'ORG-P1',
                'name' => 'Site One',
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
    }

    public function test_organization_cash_page_shows_separate_wallet_and_lifecycle(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
                'requested_amount' => '500000.00',
                'received_amount' => '500000.00',
                'utilized_amount' => '0.00',
                'status' => CashAllocationStatus::Received,
                'requested_by' => 1,
                'approved_by' => 1,
                'reference_no' => 'ORG-1',
                'requested_at' => now()->subDay(),
                'received_at' => now()->subDay(),
                'decided_at' => now()->subDay(),
            ]);

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
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->get('/finance/organization-cash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/OrganizationCash')
                ->where('summary.cash_on_hand', '500000.00')
                ->where('summary.received', '500000.00')
                ->has('allocations', 1)
                ->where('allocations.0.reference_no', 'ORG-1')
                ->has('allocations.0.lifecycle')
            );

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Office Stock',
            'amount' => '75000',
            'expense_date' => now()->toDateString(),
            'description' => 'Printer paper',
            'method' => 'cash',
        ])->assertRedirect();

        $this->get('/finance/organization-cash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Finance/OrganizationCash')
                ->where('summary.cash_on_hand', '425000.00')
                ->where('summary.utilized', '75000.00')
                ->has('use_breakdown', 1)
                ->where('use_breakdown.0.bucket', 'office_stock')
                ->where('use_breakdown.0.amount', '75000.00')
                ->has('recent_uses', 1)
            );
    }

    public function test_approving_organization_fund_floats_org_cash_on_hand(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/cash-requests', [
            'project_id' => '',
            'requested_amount' => '250000',
            'method' => 'bank',
            'reference_no' => 'ORG-REQ-1',
        ])->assertRedirect();

        $this->post('/finance/cash-requests/1/approve', [
            'approved_amount' => '250000',
            'method' => 'bank',
            'reference_no' => 'ORG-REQ-1',
        ])->assertRedirect();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $allocation = CashAllocation::first();
            $this->assertNull($allocation->project_id);
            $this->assertSame(CashAllocationStatus::Received, $allocation->status);
            $this->assertSame('250000.00', (string) $allocation->received_amount);
        });

        $this->get('/finance/organization-cash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.cash_on_hand', '250000.00')
                ->where('summary.received', '250000.00')
            );
    }

    public function test_overhead_cannot_exceed_organization_cash_on_hand(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
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

            // Plentiful project cash must not raise the overhead ceiling.
            CashAllocation::create([
                'project_id' => 1,
                'requested_amount' => '900000.00',
                'received_amount' => '900000.00',
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
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '100001',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertSessionHasErrors(['amount']);

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame(0, Expense::count());
            $this->assertSame('0.00', (string) CashAllocation::find(1)->utilized_amount);
            $this->assertSame('0.00', (string) CashAllocation::find(2)->utilized_amount);
        });

        // Spending exactly the organization balance is allowed.
        $this->post('/finance/expenses', [
            'category' => 'indirect',
            'sub_type' => 'Rent',
            'amount' => '100000',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ])->assertRedirect();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame('100000.00', (string) CashAllocation::find(1)->utilized_amount);
            $this->assertSame('0.00', (string) CashAllocation::find(1)->balance);
        });
    }

    public function test_editing_overhead_above_organization_cash_is_rejected(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
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
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $payload = [
            'category' => 'indirect',
            'sub_type' => 'Utilities',
            'amount' => '80000',
            'expense_date' => now()->toDateString(),
            'method' => 'cash',
        ];

        $this->post('/finance/expenses', $payload)->assertRedirect();

        $this->put('/finance/expenses/1', [...$payload, 'amount' => '250000'])
            ->assertSessionHasErrors(['amount']);

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            $this->assertSame('80000.00', (string) Expense::first()->amount);
            $this->assertSame('80000.00', (string) CashAllocation::first()->utilized_amount);
        });
    }

    public function test_event_inventory_overhead_uses_organization_cash(): void
    {
        $this->seedTenant();

        Tenant::where('slug', 'org-cash-co')->first()->run(function () {
            CashAllocation::create([
                'project_id' => null,
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
            'email' => 'admin@orgcash.local',
            'password' => 'password',
        ]);

        $this->post('/finance/expenses', [
            'category' => ExpenseCategory::Indirect->value,
            'sub_type' => 'Event Inventory',
            'amount' => '40000',
            'expense_date' => now()->toDateString(),
            'description' => 'Launch event materials',
            'method' => 'mobile',
        ])->assertRedirect();

        $this->get('/finance/organization-cash')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.cash_on_hand', '60000.00')
                ->where('use_breakdown.0.bucket', 'event_inventory')
            );
    }
}
