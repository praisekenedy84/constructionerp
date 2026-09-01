<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuthService;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_tracks_payments_and_recalculates_status_when_they_are_deleted(): void
    {
        $tenant = Tenant::create([
            'name' => 'Invoice Co',
            'slug' => 'invoice-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@invoice.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function (): void {
            $user = User::query()->firstOrFail();
            $customer = Customer::create([
                'name' => 'ABC Construction Ltd',
                'contact' => '+255 700 000 000',
                'address' => 'Dar es Salaam',
                'tax_information' => 'TIN 100-200-300',
            ]);
            $project = Project::create([
                'code' => 'ROAD-001',
                'name' => 'Road Construction',
                'client' => $customer->name,
                'customer_id' => $customer->id,
                'location' => 'Dodoma',
                'contract_amount' => '3000.00',
                'wht_percentage' => '0',
                'net_budget' => '3000.00',
                'physical_progress_pct' => '30',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);
            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'disbursed_amount' => '1000.00',
            ]);

            $service = app(InvoiceService::class);
            $invoice = $service->create([
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(30)->toDateString(),
                'description' => 'Phase 1 payment',
                'tax_mode' => 'exclusive',
                'tax_type' => 'VAT',
                'tax_rate' => '18',
                'deduction_type' => 'WHT',
                'deduction_rate' => '6',
                'status' => 'issued',
            ], $user);

            $this->assertSame('INV-00001', $invoice->invoice_number);
            $this->assertSame('exclusive', $invoice->tax_mode->value);
            $this->assertSame('1000.00', (string) $invoice->amount_before_tax);
            $this->assertSame('180.00', (string) $invoice->tax_amount);
            $this->assertSame('60.00', (string) $invoice->deduction_amount);
            $this->assertSame('1120.00', (string) $invoice->total_amount);
            $this->assertSame(InvoiceStatus::Issued, $invoice->status);

            $service->recordPayment($invoice, [
                'receipt_number' => 'RCPT-001',
                'payment_date' => now()->toDateString(),
                'amount_paid' => '200.00',
                'payment_method' => 'bank',
            ], $user);

            $invoice = Invoice::findOrFail($invoice->id);
            $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
            $this->assertSame('920.00', $invoice->outstanding_amount);

            $service->recordPayment($invoice, [
                'receipt_number' => 'RCPT-002',
                'payment_date' => now()->toDateString(),
                'amount_paid' => '920.00',
                'payment_method' => 'bank',
            ], $user);

            $invoice = Invoice::findOrFail($invoice->id);
            $this->assertSame(InvoiceStatus::Paid, $invoice->status);
            $this->assertSame('0.00', $invoice->outstanding_amount);
            $this->assertNotNull($invoice->paid_at);

            $secondPayment = $invoice->payments()
                ->where('receipt_number', 'RCPT-002')
                ->firstOrFail();
            $service->deletePayment($invoice, $secondPayment->id);

            $invoice = Invoice::findOrFail($invoice->id);
            $this->assertSame(InvoiceStatus::PartiallyPaid, $invoice->status);
            $this->assertSame('920.00', $invoice->outstanding_amount);
            $this->assertNull($invoice->paid_at);

            $firstPayment = $invoice->payments()
                ->where('receipt_number', 'RCPT-001')
                ->firstOrFail();
            $service->deletePayment($invoice, $firstPayment->id);

            $invoice = Invoice::findOrFail($invoice->id);
            $this->assertSame(InvoiceStatus::Issued, $invoice->status);
            $this->assertSame('1120.00', $invoice->outstanding_amount);
            $this->assertCount(0, $invoice->payments);
        });
    }

    public function test_tax_inclusive_extracts_net_and_tax_from_phase_amount(): void
    {
        $tenant = Tenant::create([
            'name' => 'Invoice Tax Co',
            'slug' => 'invoice-tax-co',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@invoice-tax.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        $tenant->run(function (): void {
            $user = User::query()->firstOrFail();
            $customer = Customer::create(['name' => 'Inclusive Client']);
            $project = Project::create([
                'code' => 'INC-001',
                'name' => 'Inclusive Project',
                'client' => $customer->name,
                'customer_id' => $customer->id,
                'location' => 'Dar',
                'contract_amount' => '1180.00',
                'wht_percentage' => '0',
                'net_budget' => '1180.00',
                'physical_progress_pct' => '0',
                'start_date' => now(),
                'end_date' => now()->addYear(),
                'status' => 'active',
            ]);
            $phase = ProjectPhase::create([
                'project_id' => $project->id,
                'sequence_no' => 1,
                'name' => 'Phase 1',
                'disbursed_amount' => '1180.00',
            ]);

            $invoice = app(InvoiceService::class)->create([
                'customer_id' => $customer->id,
                'project_id' => $project->id,
                'phase_id' => $phase->id,
                'invoice_date' => now()->toDateString(),
                'due_date' => now()->addDays(14)->toDateString(),
                'tax_type' => 'VAT',
                'tax_rate' => '18',
                'deduction_type' => 'WHT',
                'deduction_rate' => '5',
                'status' => 'draft',
            ], $user);

            $this->assertSame('inclusive', $invoice->tax_mode->value);
            $this->assertSame('1000.00', (string) $invoice->amount_before_tax);
            $this->assertSame('180.00', (string) $invoice->tax_amount);
            $this->assertSame('50.00', (string) $invoice->deduction_amount);
            $this->assertSame('1130.00', (string) $invoice->total_amount);
        });
    }
}
