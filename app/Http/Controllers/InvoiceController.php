<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceStatus;
use App\Http\Requests\RecordInvoicePaymentRequest;
use App\Http\Requests\StoreInvoiceRequest;
use App\Http\Requests\StoreInvoiceSignatureRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Services\InvoiceService;
use App\Support\ListingQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceController extends Controller
{
    public function __construct(private InvoiceService $invoiceService) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'invoices', 'read');

        $query = Invoice::query()
            ->with([
                'customer:id,name,contact,address,tax_information',
                'project:id,customer_id,code,name,location,contract_amount,status',
                'phase:id,project_id,sequence_no,name,disbursed_amount,status',
                'payments.creator:id,name',
                'signatures.signer:id,name',
            ])
            ->withSum('payments', 'amount_paid');

        $this->applyFilters($query, $request);

        $listing = ListingQuery::for($query, $request)
            ->search(['invoice_number', 'description', 'customer.name', 'project.name', 'project.code'])
            ->dateRange('invoice_date')
            ->sort(['invoice_date', 'due_date', 'created_at', 'status', 'total_amount'], 'invoice_date');

        $invoices = $listing->paginate(25);
        $invoices->getCollection()->each(function (Invoice $invoice): void {
            $invoice->payments->each(function ($payment): void {
                $payment->setAttribute(
                    'receipt_url',
                    $payment->receipt_file ? Storage::disk('public')->url($payment->receipt_file) : null,
                );
            });
            $invoice->signatures->each(function ($signature): void {
                $signature->setAttribute(
                    'signature_url',
                    Storage::disk('public')->url($signature->signature_file),
                );
            });
        });

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => $listing->filters($request->only([
                'customer_id',
                'project_id',
                'status',
                'payment_status',
            ])),
            'customers' => Customer::query()
                ->with(['projects' => fn ($query) => $query
                    ->with(['phases' => fn ($phaseQuery) => $phaseQuery->orderBy('sequence_no')])
                    ->orderBy('name')])
                ->whereHas('projects')
                ->orderBy('name')
                ->get(),
            'projects' => Project::query()
                ->whereHas('invoices')
                ->orderBy('name')
                ->get(['id', 'customer_id', 'code', 'name']),
        ]);
    }

    public function store(StoreInvoiceRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'create');

        $invoice = $this->invoiceService->create($request->validated(), $request->user());

        return back()->with('success', "Invoice {$invoice->invoice_number} created.");
    }

    public function issue(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'issue');
        $invoice = Invoice::findOrFail($id);
        $this->invoiceService->issue($invoice);

        return back()->with('success', "Invoice {$invoice->invoice_number} issued.");
    }

    public function recordPayment(RecordInvoicePaymentRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'collect');
        $invoice = Invoice::findOrFail($id);
        $this->invoiceService->recordPayment($invoice, $request->validated(), $request->user());

        return back()->with('success', "Payment recorded for {$invoice->invoice_number}.");
    }

    public function destroyPayment(Request $request, int $id, int $paymentId): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'collect');
        $invoice = Invoice::findOrFail($id);
        $this->invoiceService->deletePayment($invoice, $paymentId);

        return back()->with('success', "Payment deleted from {$invoice->invoice_number}.");
    }

    public function storeSignature(StoreInvoiceSignatureRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'sign');
        $invoice = Invoice::findOrFail($id);
        $this->invoiceService->storeSignature($invoice, $request->validated(), $request->user());

        return back()->with('success', "Signature saved for {$invoice->invoice_number}.");
    }

    public function pdf(Request $request, int $id): HttpResponse
    {
        $this->authorizePermission($request->user(), 'invoices', 'print');

        $invoice = Invoice::with([
            'customer',
            'project',
            'phase',
            'creator',
            'payments.creator',
            'signatures.signer',
        ])->withSum('payments', 'amount_paid')->findOrFail($id);

        if ($invoice->status === InvoiceStatus::Draft) {
            abort(422, 'Issue the invoice before printing it.');
        }

        if ($invoice->status === InvoiceStatus::Issued) {
            $invoice->update([
                'status' => InvoiceStatus::Printed,
                'printed_at' => now(),
            ]);
        } elseif ($invoice->printed_at === null) {
            $invoice->update(['printed_at' => now()]);
        }

        $settings = SystemSetting::where('key', 'ui_settings')->first()?->value ?? [];

        return Pdf::loadView('invoices.pdf', [
            'invoice' => $invoice->refresh(),
            'companyName' => $settings['app_name'] ?? config('app.name'),
            'companyTagline' => $settings['tagline'] ?? null,
            'companyAddress' => $settings['company_address'] ?? null,
            'companyContact' => $settings['company_contact'] ?? null,
            'companyLogoUrl' => $settings['company_logo_url'] ?? null,
        ])
            ->setPaper('a4')
            ->download("{$invoice->invoice_number}.pdf");
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        foreach (['customer_id', 'project_id'] as $foreignKey) {
            if ($request->filled($foreignKey)) {
                $query->where($foreignKey, $request->integer($foreignKey));
            }
        }

        $status = $request->string('status')->toString();
        if ($status === 'overdue') {
            $query->where('status', '!=', InvoiceStatus::Draft->value)
                ->where('status', '!=', InvoiceStatus::Paid->value)
                ->whereDate('due_date', '<', today());
        } elseif ($status !== '') {
            $query->where('status', $status);
        }

        $paymentStatus = $request->string('payment_status')->toString();
        $paymentTotal = '(select coalesce(sum(invoice_payments.amount_paid), 0) '
            .'from invoice_payments where invoice_payments.invoice_id = invoices.id)';

        if ($paymentStatus === 'unpaid') {
            $query->whereRaw("{$paymentTotal} = 0");
        } elseif ($paymentStatus === 'partially_paid') {
            $query->whereRaw("{$paymentTotal} > 0")
                ->whereRaw("{$paymentTotal} < invoices.total_amount");
        } elseif ($paymentStatus === 'paid') {
            $query->whereRaw("{$paymentTotal} >= invoices.total_amount");
        }
    }
}
