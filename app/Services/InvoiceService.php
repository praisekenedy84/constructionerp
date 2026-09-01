<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceTaxMode;
use App\Models\Invoice;
use App\Models\ProjectPhase;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    /** @param array<string, mixed> $data */
    public function create(array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($data, $user): Invoice {
            $phase = ProjectPhase::query()
                ->whereKey($data['phase_id'])
                ->where('project_id', $data['project_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $phaseAmount = bcadd((string) $phase->disbursed_amount, '0', 2);
            $taxMode = InvoiceTaxMode::tryFrom((string) ($data['tax_mode'] ?? InvoiceTaxMode::Inclusive->value))
                ?? InvoiceTaxMode::Inclusive;
            $taxRate = bcadd((string) ($data['tax_rate'] ?? 0), '0', 4);
            $deductionRate = bcadd((string) ($data['deduction_rate'] ?? 0), '0', 4);
            $amounts = $this->calculateAmounts($phaseAmount, $taxMode, $taxRate, $deductionRate);

            if (bccomp($phaseAmount, '0', 2) !== 1 || bccomp($amounts['total_amount'], '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    'phase_id' => 'The selected phase must produce a positive invoice amount.',
                ]);
            }

            $status = ($data['status'] ?? InvoiceStatus::Draft->value) === InvoiceStatus::Issued->value
                ? InvoiceStatus::Issued
                : InvoiceStatus::Draft;

            $invoice = Invoice::create([
                ...$data,
                'invoice_number' => 'PENDING-'.str()->uuid(),
                'tax_mode' => $taxMode,
                'amount_before_tax' => $amounts['amount_before_tax'],
                'tax_rate' => $taxRate,
                'tax_amount' => $amounts['tax_amount'],
                'deduction_rate' => $deductionRate,
                'deduction_amount' => $amounts['deduction_amount'],
                'total_amount' => $amounts['total_amount'],
                'status' => $status,
                'issued_at' => $status === InvoiceStatus::Issued ? now() : null,
                'created_by' => $user->id,
            ]);

            $invoice->update([
                'invoice_number' => 'INV-'.str_pad((string) $invoice->id, 5, '0', STR_PAD_LEFT),
            ]);

            return $invoice->refresh();
        });
    }

    public function issue(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            throw ValidationException::withMessages([
                'status' => 'Only draft invoices can be issued.',
            ]);
        }

        $invoice->update([
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
        ]);

        return $invoice->refresh();
    }

    /** @param array<string, mixed> $data */
    public function recordPayment(Invoice $invoice, array $data, User $user): Invoice
    {
        return DB::transaction(function () use ($invoice, $data, $user): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $locked->loadSum('payments', 'amount_paid');
            $amount = bcadd((string) $data['amount_paid'], '0', 2);

            if ($locked->status === InvoiceStatus::Draft) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Issue the invoice before recording a payment.',
                ]);
            }

            if (Carbon::parse($data['payment_date'])->lt($locked->invoice_date)) {
                throw ValidationException::withMessages([
                    'payment_date' => 'Payment date cannot be before the invoice date.',
                ]);
            }

            if (bccomp($amount, $locked->outstanding_amount, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Payment cannot exceed the outstanding invoice amount.',
                ]);
            }

            $receiptFile = $data['receipt_file'] ?? null;
            if ($receiptFile instanceof UploadedFile) {
                $receiptFile = $receiptFile->store("invoice-receipts/{$locked->id}", 'public');
            }

            $locked->payments()->create([
                ...$data,
                'receipt_file' => $receiptFile,
                'created_by' => $user->id,
            ]);

            $locked->loadSum('payments', 'amount_paid');
            $paid = bccomp($locked->paid_amount, (string) $locked->total_amount, 2) >= 0;
            $locked->update([
                'status' => $paid ? InvoiceStatus::Paid : InvoiceStatus::PartiallyPaid,
                'paid_at' => $paid ? $data['payment_date'] : null,
            ]);

            return $locked->refresh();
        });
    }

    public function deletePayment(Invoice $invoice, int $paymentId): Invoice
    {
        return DB::transaction(function () use ($invoice, $paymentId): Invoice {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $payment = $locked->payments()
                ->whereKey($paymentId)
                ->lockForUpdate()
                ->firstOrFail();
            $receiptFile = $payment->receipt_file;

            $payment->delete();

            $locked->loadSum('payments', 'amount_paid');
            $hasPayments = bccomp($locked->paid_amount, '0', 2) === 1;
            $paid = bccomp($locked->paid_amount, (string) $locked->total_amount, 2) >= 0;

            $locked->update([
                'status' => $paid
                    ? InvoiceStatus::Paid
                    : ($hasPayments
                        ? InvoiceStatus::PartiallyPaid
                        : ($locked->printed_at ? InvoiceStatus::Printed : InvoiceStatus::Issued)),
                'paid_at' => $paid
                    ? $locked->payments()->max('payment_date')
                    : null,
            ]);

            if ($receiptFile) {
                Storage::disk('public')->delete($receiptFile);
            }

            return $locked->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function storeSignature(Invoice $invoice, array $data, User $user): void
    {
        $existing = $invoice->signatures()
            ->where('signature_type', $data['signature_type'])
            ->first();

        $path = $data['signature_file']->store("invoice-signatures/{$invoice->id}", 'public');

        if ($existing?->signature_file) {
            Storage::disk('public')->delete($existing->signature_file);
        }

        $invoice->signatures()->updateOrCreate(
            ['signature_type' => $data['signature_type']],
            [
                'signature_file' => $path,
                'signed_by' => $user->id,
                'signed_date' => $data['signed_date'] ?? now(),
            ],
        );
    }

    /**
     * @return array{
     *     amount_before_tax: string,
     *     tax_amount: string,
     *     deduction_amount: string,
     *     total_amount: string
     * }
     */
    public function calculateAmounts(
        string $phaseAmount,
        InvoiceTaxMode $taxMode,
        string $taxRate,
        string $deductionRate,
    ): array {
        $phaseAmount = bcadd($phaseAmount, '0', 2);
        $taxRate = bcadd($taxRate, '0', 4);
        $deductionRate = bcadd($deductionRate, '0', 4);

        if ($taxMode === InvoiceTaxMode::Inclusive && bccomp($taxRate, '0', 4) === 1) {
            // Gross phase amount already includes tax: extract net and tax portion.
            $divisor = bcadd('1', bcdiv($taxRate, '100', 6), 6);
            $amountBeforeTax = bcdiv($phaseAmount, $divisor, 2);
            $taxAmount = bcsub($phaseAmount, $amountBeforeTax, 2);
            $grossWithTax = $phaseAmount;
        } else {
            $amountBeforeTax = $phaseAmount;
            $taxAmount = bcmul($amountBeforeTax, bcdiv($taxRate, '100', 6), 2);
            $grossWithTax = bcadd($amountBeforeTax, $taxAmount, 2);
        }

        $deductionAmount = bcmul($amountBeforeTax, bcdiv($deductionRate, '100', 6), 2);
        $totalAmount = bcsub($grossWithTax, $deductionAmount, 2);

        return [
            'amount_before_tax' => $amountBeforeTax,
            'tax_amount' => $taxAmount,
            'deduction_amount' => $deductionAmount,
            'total_amount' => $totalAmount,
        ];
    }
}
