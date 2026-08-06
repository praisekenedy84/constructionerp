<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\EquipmentMaintenance;
use App\Models\MoneyAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderPayment;
use App\Models\Requisition;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\MoneyAccountService;
use App\Services\ProcurementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderAllocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requisition_can_fund_multiple_purchase_orders_without_exceeding_balance(): void
    {
        $tenant = Tenant::create([
            'name' => 'Purchase Test Co',
            'slug' => 'purchase-test-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Procurement User',
            'email' => 'procurement@purchase.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            $user = User::firstOrFail();
            $supplier = Supplier::create([
                'name' => 'Parts Supplier',
                'contact_info' => 'Dar es Salaam',
            ]);
            $vehicle = Equipment::create([
                'name' => 'Truck T 123 ABC',
                'type' => 'truck',
                'status' => 'available',
            ]);
            $requisition = Requisition::create([
                'requisition_no' => 'REQ-PO-001',
                'project_id' => null,
                'boq_item_id' => null,
                'department' => 'Operations',
                'resource_type' => 'materials',
                'requestor_id' => $user->id,
                'status' => 'approved',
                'fulfillment_type' => 'direct_supplier_payment',
                'original_amount' => '10000000.00',
                'fulfilled_amount' => '0.00',
            ]);

            $service = app(ProcurementService::class);
            $first = $service->createPOFromRequisition($requisition, $supplier, [
                'equipment_id' => $vehicle->id,
                'purchase_date' => '2026-08-06',
                'items' => [
                    ['name' => 'Tyres', 'quantity' => '4', 'unit_price' => '1000000'],
                ],
            ], $user);

            $second = $service->createPOFromRequisition($requisition, $supplier, [
                'equipment_id' => null,
                'purchase_date' => '2026-08-06',
                'items' => [
                    ['name' => 'Cement', 'quantity' => '100', 'unit_price' => '30000'],
                ],
            ], $user);

            $this->assertSame('4000000.00', $first['purchase_order']->total_amount);
            $this->assertSame('3000000.00', $second['purchase_order']->total_amount);
            $this->assertSame('3000000.00', $second['remaining_amount']);
            $this->assertCount(2, PurchaseOrder::all());
            $this->assertDatabaseHas('purchase_order_items', [
                'name' => 'Tyres',
                'total_amount' => '4000000',
            ]);
            $this->assertDatabaseHas('equipment_maintenances', [
                'equipment_id' => $vehicle->id,
                'purchase_order_id' => $first['purchase_order']->id,
                'cost' => '4000000',
            ]);
            $this->assertSame(1, EquipmentMaintenance::count());

            try {
                $service->createPOFromRequisition($requisition, $supplier, [
                    'purchase_date' => '2026-08-06',
                    'items' => [
                        ['name' => 'Over allocation', 'quantity' => '1', 'unit_price' => '3000001'],
                    ],
                ], $user);
                $this->fail('Expected the purchase order to exceed the remaining balance.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('items', $exception->errors());
            }

            $this->assertSame(2, PurchaseOrder::count());
        });

        tenancy()->end();
    }

    public function test_purchase_order_can_be_created_over_http_with_items_and_payment(): void
    {
        $tenant = Tenant::create([
            'name' => 'Purchase Http Co',
            'slug' => 'purchase-http-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Http Procurement User',
            'email' => 'http-procurement@purchase.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            $user = User::firstOrFail();
            app(MoneyAccountService::class)
                ->ensureFinanceAccount($user)
                ->update(['balance' => '1000000.00']);
            Supplier::create([
                'name' => 'Http Supplier',
                'contact_info' => 'Dodoma',
            ]);
            Requisition::create([
                'requisition_no' => 'REQ-HTTP-001',
                'project_id' => null,
                'boq_item_id' => null,
                'department' => 'Operations',
                'resource_type' => 'materials',
                'requestor_id' => $user->id,
                'status' => 'approved',
                'fulfillment_type' => 'direct_supplier_payment',
                'original_amount' => '900000.00',
                'fulfilled_amount' => '0.00',
            ]);
        });

        tenancy()->end();

        $this->post('/login', [
            'email' => 'http-procurement@purchase.local',
            'password' => 'password',
        ])->assertRedirect('/dashboard');

        $this->get('/procurement/purchase-orders')->assertOk();

        $this->post('/procurement/purchase-orders', [
            'requisition_id' => 1,
            'supplier_id' => 1,
            'equipment_id' => '',
            'purchase_date' => '2026-08-06',
            'items' => [
                ['name' => 'Boots', 'quantity' => '10', 'unit_price' => '50000'],
            ],
            'payment_amount' => '300000',
            'payment_method' => 'bank',
            'payment_reference_no' => 'HTTP-PO-001',
        ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->get('/procurement/purchase-orders?status=draft&payment_status=partially_paid&supplier_id=1&from=2026-08-06&to=2026-08-06')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Procurement/PurchaseOrders')
                ->has('purchase_orders.data', 1)
                ->where('filters.status', 'draft')
                ->where('filters.payment_status', 'partially_paid')
                ->where('filters.supplier_id', '1')
                ->where('summary.order_count', 1)
                ->where('summary.total_amount', '500000.00')
                ->where('summary.paid_amount', '300000.00')
                ->where('summary.outstanding_amount', '200000.00')
            );

        $this->get('/procurement/purchase-orders?payment_status=paid')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('purchase_orders.data', 0)
                ->where('summary.order_count', 0)
                ->where('summary.total_amount', '0.00')
                ->where('summary.paid_amount', '0.00')
                ->where('summary.outstanding_amount', '0.00')
            );

        $tenant->run(function () {
            $purchaseOrder = PurchaseOrder::with('items')->firstOrFail()->loadSum('payments', 'amount');

            $this->assertSame('PO-000001', $purchaseOrder->purchase_order_no);
            $this->assertSame('500000.00', $purchaseOrder->total_amount);
            $this->assertSame('300000.00', $purchaseOrder->paid_amount);
            $this->assertSame('200000.00', $purchaseOrder->outstanding_amount);
            $this->assertCount(1, $purchaseOrder->items);
        });

        tenancy()->end();
    }

    public function test_payment_follows_a_requisition_already_fulfilled_item_by_item(): void
    {
        $tenant = Tenant::create([
            'name' => 'Item Scope Test Co',
            'slug' => 'item-scope-test-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Item Scope User',
            'email' => 'item-scope@purchase.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            $user = User::firstOrFail();
            app(MoneyAccountService::class)
                ->ensureFinanceAccount($user)
                ->update(['balance' => '1000000.00']);
            $supplier = Supplier::create([
                'name' => 'Item Scope Supplier',
                'contact_info' => 'Mwanza',
            ]);
            $requisition = Requisition::create([
                'requisition_no' => 'REQ-ITEMS-001',
                'project_id' => null,
                'boq_item_id' => null,
                'department' => 'Operations',
                'resource_type' => 'materials',
                'requestor_id' => $user->id,
                'status' => 'partially_fulfilled',
                'fulfillment_type' => 'direct_supplier_payment',
                'fulfillment_scope' => 'items',
                'original_amount' => '500000.00',
                'fulfilled_amount' => '250000.00',
            ]);
            $item = $requisition->items()->create([
                'description' => 'Cement bags',
                'unit' => 'bag',
                'quantity' => '2.000',
                'fulfilled_quantity' => '1.000',
                'unit_cost' => '250000.00',
                'line_total' => '500000.00',
            ]);

            $result = app(ProcurementService::class)->createPOFromRequisition(
                $requisition,
                $supplier,
                [
                    'purchase_date' => '2026-08-06',
                    'items' => [
                        ['name' => 'Cement', 'quantity' => '4', 'unit_price' => '50000'],
                    ],
                    'payment_amount' => '100000',
                    'payment_method' => 'cash',
                    'payment_reference_no' => 'ITEMS-PO-001',
                ],
                $user,
            );

            $purchaseOrder = $result['purchase_order'];
            $this->assertSame('200000.00', $purchaseOrder->total_amount);
            $this->assertSame('100000.00', $purchaseOrder->paid_amount);
            $this->assertSame('100000.00', $purchaseOrder->outstanding_amount);

            $requisition->refresh();
            $this->assertSame('350000.00', $requisition->fulfilled_amount);
            $this->assertSame('1.400', $item->refresh()->fulfilled_quantity);
        });

        tenancy()->end();
    }

    public function test_partial_purchase_payment_becomes_supplier_debt_and_can_be_settled(): void
    {
        $tenant = Tenant::create([
            'name' => 'Supplier Debt Test Co',
            'slug' => 'supplier-debt-test-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Finance Procurement User',
            'email' => 'finance-procurement@purchase.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function () {
            $user = User::firstOrFail();
            app(MoneyAccountService::class)
                ->ensureFinanceAccount($user)
                ->update(['balance' => '1000000.00']);
            $supplier = Supplier::create([
                'name' => 'Credit Supplier',
                'contact_info' => 'Dar es Salaam',
            ]);
            $requisition = Requisition::create([
                'requisition_no' => 'REQ-DEBT-001',
                'project_id' => null,
                'boq_item_id' => null,
                'department' => 'Operations',
                'resource_type' => 'materials',
                'requestor_id' => $user->id,
                'status' => 'approved',
                'fulfillment_type' => 'direct_supplier_payment',
                'original_amount' => '1000000.00',
                'fulfilled_amount' => '0.00',
            ]);

            $service = app(ProcurementService::class);
            $result = $service->createPOFromRequisition($requisition, $supplier, [
                'purchase_date' => '2026-08-06',
                'items' => [
                    ['name' => 'Boots', 'quantity' => '10', 'unit_price' => '50000'],
                ],
                'payment_amount' => '300000',
                'payment_method' => 'bank',
                'payment_reference_no' => 'BANK-PO-001',
            ], $user);

            $purchaseOrder = $result['purchase_order'];
            $this->assertSame('500000.00', $purchaseOrder->total_amount);
            $this->assertSame('300000.00', $purchaseOrder->paid_amount);
            $this->assertSame('200000.00', $purchaseOrder->outstanding_amount);
            $this->assertSame('partially_paid', $purchaseOrder->payment_status);
            $this->assertSame('300000.00', (string) $requisition->fresh()->fulfilled_amount);
            $this->assertSame('700000.00', (string) MoneyAccount::firstOrFail()->balance);

            $service->recordPayment($purchaseOrder, '200000', $user, [
                'method' => 'bank',
                'reference_no' => 'BANK-PO-002',
                'paid_at' => '2026-08-07',
            ]);

            $purchaseOrder = $purchaseOrder->fresh()->loadSum('payments', 'amount');
            $this->assertSame('500000.00', $purchaseOrder->paid_amount);
            $this->assertSame('0.00', $purchaseOrder->outstanding_amount);
            $this->assertSame('paid', $purchaseOrder->payment_status);
            $this->assertSame(2, PurchaseOrderPayment::count());
            $this->assertSame('500000.00', (string) $requisition->fresh()->fulfilled_amount);
            $this->assertSame('500000.00', (string) MoneyAccount::firstOrFail()->balance);
        });

        tenancy()->end();
    }
}
