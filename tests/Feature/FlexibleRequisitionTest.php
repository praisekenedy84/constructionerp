<?php

namespace Tests\Feature;

use App\Enums\RequisitionResourceType;
use App\Enums\RequisitionStatus;
use App\Models\BoqItem;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FlexibleRequisitionTest extends TestCase
{
    use RefreshDatabase;

    private function seedTenant(): Tenant
    {
        $tenant = Tenant::create([
            'name' => 'Flexible Req Co',
            'slug' => 'flexible-req-co',
        ]);

        $auth = app(AuthService::class);
        $auth->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@flexible.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        $tenant->run(function () {
            app(PermissionService::class)->syncTenantPermissions();

            Project::create([
                'code' => 'FX-001',
                'name' => 'Flexible Project',
                'client' => 'Client',
                'location' => 'Site',
                'contract_amount' => '10000000.00',
                'wht_percentage' => '5.00',
                'physical_progress_pct' => '0.00',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);

            InventoryItem::create([
                'code' => 'CEM-50',
                'name' => 'Cement 50kg',
                'unit' => 'bag',
                'category' => 'materials',
                'reorder_point' => '10.000',
            ]);
        });

        tenancy()->end();

        return $tenant;
    }

    public function test_can_create_requisition_without_boq_using_new_item(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'boq_item_id' => null,
            'department' => 'Site',
            'resource_type' => 'cash',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Petty cash for local transport',
                    'unit' => 'lump',
                    'quantity' => '1',
                    'unit_cost' => '150000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            $req = Requisition::with(['items', 'category'])->first();
            $this->assertNotNull($req);
            $this->assertNull($req->boq_item_id);
            $this->assertSame(RequisitionResourceType::Other, $req->resource_type);
            $this->assertSame('Cash', $req->category?->name);
            $this->assertSame(RequisitionStatus::Draft, $req->status);
            $this->assertSame('150000.00', (string) $req->original_amount);
            $this->assertCount(1, $req->items);
            $this->assertNull($req->items->first()->boq_item_id);
            $this->assertNull($req->items->first()->inventory_item_id);
            $this->assertSame('Petty cash for local transport', $req->items->first()->description);
            $this->assertSame('lump', $req->items->first()->unit);
        });
    }

    public function test_can_create_requisition_from_inventory_catalog(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Stores',
            'fulfillment_type' => 'stock_issue',
            'addressed_to' => 'storekeeper',
            'items' => [
                [
                    'inventory_item_id' => 1,
                    'description' => 'Cement 50kg',
                    'unit' => 'bag',
                    'quantity' => '20',
                    'unit_cost' => '18000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            $req = Requisition::with('items')->first();
            $this->assertNotNull($req);
            $this->assertNull($req->boq_item_id);
            $this->assertSame(1, $req->items->first()->inventory_item_id);
            $this->assertSame('360000.00', (string) $req->original_amount);
            $this->assertCount(0, BoqItem::all());
        });
    }

    public function test_can_create_generic_labor_style_line(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Site',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Casual workers for excavation',
                    'unit' => 'worker-day',
                    'quantity' => '50',
                    'unit_cost' => '25000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            $req = Requisition::with('items')->first();
            $this->assertNotNull($req);
            $this->assertSame(RequisitionResourceType::Other, $req->resource_type);
            $this->assertSame('1250000.00', (string) $req->original_amount);
            $item = $req->items->first();
            $this->assertSame('50.000', (string) $item->quantity);
            $this->assertSame('worker-day', $item->unit);
            $this->assertSame('25000.00', (string) $item->unit_cost);
            $this->assertNull($item->details);
        });
    }

    public function test_can_update_draft_requisition(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Site',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Casual workers',
                    'unit' => 'worker-day',
                    'quantity' => '8',
                    'unit_cost' => '20000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $requisitionId = null;
        $tenant->run(function () use (&$requisitionId) {
            $requisitionId = Requisition::firstOrFail()->id;
        });

        $this->put("/requisitions/{$requisitionId}", [
            'project_id' => 1,
            'department' => 'Site Updated',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Casual workers for concrete pour',
                    'unit' => 'worker-day',
                    'quantity' => '24',
                    'unit_cost' => '25000',
                ],
            ],
        ])->assertRedirect();

        $tenant->run(function () {
            $req = Requisition::with('items')->firstOrFail();
            $this->assertSame('Site Updated', $req->department);
            $this->assertSame('600000.00', (string) $req->original_amount);
            $this->assertSame('Casual workers for concrete pour', $req->items->first()->description);
            $this->assertSame('24.000', (string) $req->items->first()->quantity);
        });
    }

    public function test_cannot_edit_non_draft_requisition(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            Requisition::create([
                'requisition_no' => 'REQ-2026-00999',
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => RequisitionResourceType::Other,
                'requestor_id' => 1,
                'status' => RequisitionStatus::UnderReview,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '100000.00',
            ]);
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->get('/requisitions/1/edit')->assertRedirect('/requisitions/1');

        $this->put('/requisitions/1', [
            'project_id' => 1,
            'department' => 'Hacked',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Should fail',
                    'quantity' => '1',
                    'unit_cost' => '1',
                ],
            ],
        ])->assertRedirect('/requisitions/1');

        $tenant->run(function () {
            $this->assertSame('Site', Requisition::firstOrFail()->department);
            $this->assertSame(RequisitionStatus::UnderReview, Requisition::firstOrFail()->status);
        });
    }

    public function test_can_create_requisition_with_multiple_generic_lines(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Site',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Casual excavation support',
                    'unit' => 'worker-day',
                    'quantity' => '50',
                    'unit_cost' => '25000',
                ],
                [
                    'description' => 'Skilled masons',
                    'unit' => 'worker-day',
                    'quantity' => '12',
                    'unit_cost' => '40000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            $req = Requisition::with('items')->firstOrFail();
            $this->assertCount(2, $req->items);
            // 50*25000 + 12*40000 = 1,250,000 + 480,000
            $this->assertSame('1730000.00', (string) $req->original_amount);
            $this->assertSame('Casual excavation support', $req->items[0]->description);
            $this->assertSame('Skilled masons', $req->items[1]->description);
        });
    }

    public function test_can_create_requisition_with_multiple_recipients_and_categories(): void
    {
        $this->seedTenant();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $categoryIds = [];
        $positionId = null;
        $tenant->run(function () use (&$categoryIds, &$positionId) {
            $categoryIds = \App\Models\RequisitionCategory::query()
                ->orderBy('id')
                ->limit(2)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($categoryIds) < 2) {
                $extra = \App\Models\RequisitionCategory::create([
                    'name' => 'Extra Category',
                    'is_active' => true,
                    'sort_order' => 99,
                ]);
                $categoryIds[] = $extra->id;
            }

            $positionId = \App\Models\Position::query()->value('id');
            if (! $positionId) {
                $positionId = \App\Models\Position::create([
                    'name' => 'Site Foreman',
                    'is_active' => true,
                    'sort_order' => 1,
                ])->id;
            }
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Site',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Line for Alice',
                    'unit' => 'lump',
                    'quantity' => '1',
                    'unit_cost' => '5000',
                    'requisition_category_id' => $categoryIds[0],
                    'recipient_name' => 'Alice Worker',
                    'position_id' => $positionId,
                ],
                [
                    'description' => 'Line for Bob',
                    'unit' => 'lump',
                    'quantity' => '1',
                    'unit_cost' => '3000',
                    'requisition_category_id' => $categoryIds[1],
                    'recipient_name' => 'Bob Helper',
                    'position_id' => null,
                ],
            ],
        ])->assertRedirect();

        $tenant->run(function () use ($categoryIds, $positionId) {
            $req = Requisition::with(['items', 'recipients', 'categories'])->latest('id')->firstOrFail();
            $this->assertCount(2, $req->items);
            $this->assertSame((int) $categoryIds[0], (int) $req->items[0]->requisition_category_id);
            $this->assertSame((int) $categoryIds[1], (int) $req->items[1]->requisition_category_id);
            $this->assertSame('Alice Worker', $req->items[0]->recipient_name);
            $this->assertSame((int) $positionId, (int) $req->items[0]->position_id);
            $this->assertSame('Bob Helper', $req->items[1]->recipient_name);
            $this->assertEqualsCanonicalizing(
                $categoryIds,
                $req->categories->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
            $this->assertCount(2, $req->recipients);
        });
    }

    public function test_new_requisition_number_skips_soft_deleted_numbers(): void
    {
        $this->seedTenant();

        $year = now()->year;
        $softDeletedNo = sprintf('REQ-%d-00006', $year);

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () use ($softDeletedNo) {
            $req = Requisition::create([
                'requisition_no' => $softDeletedNo,
                'project_id' => 1,
                'department' => 'Site',
                'resource_type' => 'other',
                'requestor_id' => 1,
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => 'cash_disbursement',
                'addressed_to' => 'finance',
                'original_amount' => '100.00',
            ]);
            $req->delete();
        });
        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Site',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'After soft-deleted number',
                    'unit' => 'lump',
                    'quantity' => '1',
                    'unit_cost' => '1000',
                ],
            ],
        ])->assertRedirect();

        $tenant->run(function () use ($year, $softDeletedNo) {
            $this->assertNotNull(Requisition::withTrashed()->where('requisition_no', $softDeletedNo)->first());
            $created = Requisition::whereNull('deleted_at')->latest('id')->firstOrFail();
            $this->assertSame(sprintf('REQ-%d-00007', $year), $created->requisition_no);
        });
    }

    public function test_optional_days_multiplies_line_total(): void
    {
        $this->seedTenant();

        $this->post('/login', [
            'email' => 'engineer@flexible.local',
            'password' => 'password',
        ])->assertRedirect();

        $this->post('/requisitions', [
            'project_id' => 1,
            'department' => 'Plant',
            'fulfillment_type' => 'cash_disbursement',
            'addressed_to' => 'finance',
            'items' => [
                [
                    'description' => 'Excavator hire',
                    'unit' => 'machine',
                    'quantity' => '1',
                    'days' => '3',
                    'unit_cost' => '800000',
                ],
                [
                    'description' => 'Diesel drums',
                    'unit' => 'drum',
                    'quantity' => '2',
                    'unit_cost' => '150000',
                ],
            ],
        ])->assertRedirect();

        $tenant = Tenant::where('slug', 'flexible-req-co')->firstOrFail();
        $tenant->run(function () {
            $req = Requisition::with('items')->firstOrFail();
            // 1 × 800000 × 3 days + 2 × 150000 = 2,700,000
            $this->assertSame('2700000.00', (string) $req->original_amount);

            $hire = $req->items->firstWhere('description', 'Excavator hire');
            $this->assertNotNull($hire);
            $this->assertSame('2400000.00', (string) $hire->line_total);
            $this->assertSame('3.000', $hire->days());
            $this->assertSame(['days' => '3.000'], $hire->details);

            $diesel = $req->items->firstWhere('description', 'Diesel drums');
            $this->assertNotNull($diesel);
            $this->assertSame('300000.00', (string) $diesel->line_total);
            $this->assertNull($diesel->days());
            $this->assertNull($diesel->details);
        });
    }
}
