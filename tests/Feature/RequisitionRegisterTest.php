<?php

namespace Tests\Feature;

use App\Enums\RequisitionResourceType;
use App\Enums\RequisitionStatus;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use App\Models\RequisitionItem;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequisitionRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): void
    {
        $tenant = Tenant::create(['name' => 'Register Co', 'slug' => 'register-co']);

        $admin = app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@register-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () use ($admin) {
            app(PermissionService::class)->syncTenantPermissions();

            $project = Project::create([
                'code' => 'RG-001',
                'name' => 'Register Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);

            $category = RequisitionCategory::query()->where('name', 'Materials')->first()
                ?? RequisitionCategory::create([
                    'name' => 'Materials',
                    'is_active' => true,
                    'sort_order' => 1,
                ]);

            $paid = Requisition::create([
                'requisition_no' => 'REQ-REG-001',
                'project_id' => $project->id,
                'department' => 'Site',
                'requisition_category_id' => $category->id,
                'resource_type' => RequisitionResourceType::Other,
                'requestor_id' => $admin->id,
                'status' => RequisitionStatus::Fulfilled,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '150000.00',
                'created_at' => now()->subDay(),
            ]);

            RequisitionItem::create([
                'requisition_id' => $paid->id,
                'description' => 'Cement bags',
                'unit' => 'bag',
                'quantity' => '10.000',
                'unit_cost' => '10000.00',
                'line_total' => '100000.00',
            ]);

            RequisitionItem::create([
                'requisition_id' => $paid->id,
                'description' => 'Sand',
                'unit' => 'trip',
                'quantity' => '1.000',
                'unit_cost' => '50000.00',
                'line_total' => '50000.00',
            ]);

            $pending = Requisition::create([
                'requisition_no' => 'REQ-REG-002',
                'project_id' => $project->id,
                'department' => 'Site',
                'requisition_category_id' => $category->id,
                'resource_type' => RequisitionResourceType::Other,
                'requestor_id' => $admin->id,
                'status' => RequisitionStatus::Approved,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '30000.00',
                'created_at' => now(),
            ]);

            RequisitionItem::create([
                'requisition_id' => $pending->id,
                'description' => 'Fuel',
                'unit' => 'Ltr',
                'quantity' => '10.000',
                'unit_cost' => '3000.00',
                'line_total' => '30000.00',
            ]);
        });

        tenancy()->end();
    }

    public function test_register_lists_line_rows_with_summary(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@register-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Index')
                ->has('rows.data', 3)
                ->where('summary.total_requested', '180000.00')
                ->where('summary.total_paid', '150000.00')
                ->where('summary.total_pending', '30000.00')
                ->where('rows.data.0.description', fn ($value) => is_string($value) && $value !== '')
            );
    }

    public function test_register_filters_by_status_and_updates_summary(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@register-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions?status=fulfilled')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Requisitions/Index')
                ->has('rows.data', 2)
                ->where('summary.total_requested', '150000.00')
                ->where('summary.total_paid', '150000.00')
                ->where('summary.total_pending', '0.00')
            );
    }

    public function test_register_export_downloads_excel(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'admin@register-co.local',
            'password' => 'password',
        ]);

        $this->get('/requisitions/export')
            ->assertOk()
            ->assertHeader('content-disposition');
    }
}
