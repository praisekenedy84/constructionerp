<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\CashAllocationStatus;
use App\Enums\DepositSource;
use App\Models\AccountTransaction;
use App\Models\Advance;
use App\Models\ApprovalAction;
use App\Models\ApprovalStep;
use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqSection;
use App\Models\BudgetTransaction;
use App\Models\CashAllocation;
use App\Models\CashDisbursement;
use App\Models\Employee;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentFuelLog;
use App\Models\EquipmentMaintenance;
use App\Models\Expense;
use App\Models\GoodsReceipt;
use App\Models\InventoryIssue;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\InvoiceSignature;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\ProjectComplianceItem;
use App\Models\ProjectComplianceItemEvent;
use App\Models\ProjectComplianceRule;
use App\Models\ProjectPhase;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\PurchaseOrderPayment;
use App\Models\Quotation;
use App\Models\RecipientAttendance;
use App\Models\Requisition;
use App\Models\RequisitionAttachment;
use App\Models\RequisitionItem;
use App\Models\RequisitionRecipient;
use App\Models\RequisitionStatusHistory;
use App\Models\Sale;
use App\Models\SaleReceivablePayment;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\User;
use App\Models\Valuation;
use App\Models\ValuationDeduction;
use App\Models\WithholdingTaxRate;
use App\Models\WorkflowConfig;
use Illuminate\Support\Facades\DB;

class ProjectDeletionService
{
    public function __construct(
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    public function purge(Project $project, User $actor): void
    {
        DB::transaction(function () use ($project, $actor) {
            $project = Project::withTrashed()->lockForUpdate()->findOrFail($project->id);
            $label = "{$project->code}";

            $this->reverseProjectCash($project, $actor, $label);
            $this->deleteProjectChildren($project);
            $project->forceDelete();
        });
    }

    private function reverseProjectCash(Project $project, User $actor, string $label): void
    {
        $saleIds = Sale::query()->where('project_id', $project->id)->pluck('id');

        // 1) Sale / retention receivable collections → remove from company account.
        $payments = SaleReceivablePayment::query()
            ->whereIn('sale_id', $saleIds)
            ->whereNotNull('account_transaction_id')
            ->get();

        foreach ($payments as $payment) {
            $tx = AccountTransaction::find($payment->account_transaction_id);
            if ($tx && $tx->type === AccountTransactionType::ReceivablePayment) {
                $this->moneyAccountService->reverseReceivablePayment(
                    $tx,
                    $actor,
                    "Project {$label} deleted — reversing receivable collection {$tx->id}",
                );
            }
        }

        // 2) Retention-release deposits tagged to this project → remove from company account.
        $retentionDeposits = AccountTransaction::query()
            ->where('type', AccountTransactionType::Deposit)
            ->where('deposit_source', DepositSource::RetentionRelease->value)
            ->where('reference_entity_type', 'project')
            ->where('reference_entity_id', $project->id)
            ->get();

        foreach ($retentionDeposits as $tx) {
            $this->moneyAccountService->reverseDeposit(
                $tx,
                $actor,
                "Project {$label} deleted — reversing retention release deposit {$tx->id}",
            );
        }

        // 3) Finance disbursements for project expenses / requisitions → return to accountant cash on hand.
        $expenseIds = Expense::withTrashed()->where('project_id', $project->id)->pluck('id');
        $requisitionIds = Requisition::withTrashed()->where('project_id', $project->id)->pluck('id');

        $disbursements = CashDisbursement::query()
            ->where(function ($q) use ($expenseIds, $requisitionIds) {
                $q->whereIn('expense_id', $expenseIds)
                    ->orWhereIn('requisition_id', $requisitionIds);
            })
            ->whereNotNull('account_transaction_id')
            ->get();

        foreach ($disbursements as $disbursement) {
            $tx = AccountTransaction::find($disbursement->account_transaction_id);
            if ($tx && $tx->type === AccountTransactionType::Disbursement) {
                $this->moneyAccountService->reverseFinanceDisbursement(
                    $tx,
                    $actor,
                    "Project {$label} deleted — reversing disbursement {$tx->id}",
                );
            }
        }

        // 4) Project-linked fund requests: undo manager → finance so wipe does not leave earmarked cash.
        $allocations = CashAllocation::query()
            ->where('project_id', $project->id)
            ->whereIn('status', [CashAllocationStatus::Approved, CashAllocationStatus::Received])
            ->get();

        foreach ($allocations as $allocation) {
            $hasTransfer = AccountTransaction::query()
                ->where('reference_entity_type', 'cash_allocation')
                ->where('reference_entity_id', $allocation->id)
                ->where('type', AccountTransactionType::TransferIn)
                ->exists();

            if ($hasTransfer) {
                $this->moneyAccountService->reverseTransferToFinance(
                    $allocation,
                    $actor,
                    "Project {$label} deleted — reversing fund request #{$allocation->id}",
                );
            }
        }
    }

    private function deleteProjectChildren(Project $project): void
    {
        $projectId = $project->id;
        $requisitionIds = Requisition::withTrashed()->where('project_id', $projectId)->pluck('id');
        $locationIds = StockLocation::withTrashed()->where('project_id', $projectId)->pluck('id');
        $saleIds = Sale::query()->where('project_id', $projectId)->pluck('id');
        $expenseIds = Expense::withTrashed()->where('project_id', $projectId)->pluck('id');
        $poIds = PurchaseOrder::withTrashed()
            ->whereIn('requisition_id', $requisitionIds)
            ->pluck('id');

        // --- Sales ---
        SaleReceivablePayment::query()->whereIn('sale_id', $saleIds)->delete();
        Sale::query()->where('project_id', $projectId)->delete();

        // --- Invoices ---
        $invoiceIds = Invoice::withTrashed()->where('project_id', $projectId)->pluck('id');
        InvoicePayment::query()->whereIn('invoice_id', $invoiceIds)->delete();
        InvoiceSignature::query()->whereIn('invoice_id', $invoiceIds)->delete();
        Invoice::withTrashed()->where('project_id', $projectId)->forceDelete();

        // --- Valuations / IPC expenses ---
        $valuationIds = Valuation::withTrashed()->where('project_id', $projectId)->pluck('id');
        ValuationDeduction::query()->whereIn('valuation_id', $valuationIds)->delete();
        $ipcExpenseIds = Expense::withTrashed()->whereIn('valuation_id', $valuationIds)->pluck('id');
        CashDisbursement::query()->whereIn('expense_id', $ipcExpenseIds->merge($expenseIds)->unique())->delete();
        Expense::withTrashed()->whereIn('id', $ipcExpenseIds->merge($expenseIds)->unique())->forceDelete();
        Valuation::withTrashed()->where('project_id', $projectId)->forceDelete();

        // --- Procurement / inventory ---
        GoodsReceipt::query()->whereIn('purchase_order_id', $poIds)->delete();
        PurchaseOrderPayment::query()->whereIn('purchase_order_id', $poIds)->delete();
        EquipmentMaintenance::query()->whereIn('purchase_order_id', $poIds)->delete();
        PurchaseOrderItem::query()->whereIn('purchase_order_id', $poIds)->delete();
        PurchaseOrder::withTrashed()->whereIn('id', $poIds)->forceDelete();

        InventoryIssue::query()
            ->where(function ($q) use ($requisitionIds, $locationIds) {
                $q->whereIn('requisition_id', $requisitionIds)
                    ->orWhereIn('stock_location_id', $locationIds);
            })
            ->delete();

        InventoryTransaction::query()->whereIn('stock_location_id', $locationIds)->delete();
        StockBalance::query()->whereIn('stock_location_id', $locationIds)->delete();

        Quotation::query()->whereIn('requisition_id', $requisitionIds)->delete();

        // --- Requisition cash disbursements & graph ---
        CashDisbursement::query()->whereIn('requisition_id', $requisitionIds)->delete();

        $stepIds = ApprovalStep::query()->whereIn('requisition_id', $requisitionIds)->pluck('id');
        ApprovalAction::query()->whereIn('approval_step_id', $stepIds)->delete();
        ApprovalStep::query()->whereIn('requisition_id', $requisitionIds)->delete();
        RequisitionAttachment::query()->whereIn('requisition_id', $requisitionIds)->delete();
        RequisitionStatusHistory::query()->whereIn('requisition_id', $requisitionIds)->delete();
        RequisitionRecipient::query()->whereIn('requisition_id', $requisitionIds)->delete();
        DB::table('requisition_requisition_category')->whereIn('requisition_id', $requisitionIds)->delete();
        RequisitionItem::query()->whereIn('requisition_id', $requisitionIds)->delete();

        PayrollRun::withTrashed()
            ->whereIn('requisition_id', $requisitionIds)
            ->update(['requisition_id' => null]);

        Requisition::withTrashed()->where('project_id', $projectId)->forceDelete();

        // --- Cash allocations for this project ---
        $allocationIds = CashAllocation::query()->where('project_id', $projectId)->pluck('id');
        CashDisbursement::query()->whereIn('cash_allocation_id', $allocationIds)->delete();
        CashAllocation::query()->where('project_id', $projectId)->delete();

        // --- Budget ---
        BudgetTransaction::query()->where('project_id', $projectId)->delete();

        // --- People / payroll / equipment ---
        Advance::query()->where('project_id', $projectId)->delete();

        $payrollIds = PayrollRun::withTrashed()->where('project_id', $projectId)->pluck('id');
        $payrollItemIds = PayrollItem::query()->whereIn('payroll_run_id', $payrollIds)->pluck('id');
        PayrollDeduction::query()->whereIn('payroll_item_id', $payrollItemIds)->delete();
        PayrollItem::query()->whereIn('payroll_run_id', $payrollIds)->delete();
        PayrollRun::withTrashed()->where('project_id', $projectId)->forceDelete();

        DB::table('employee_project')->where('project_id', $projectId)->delete();
        Employee::withTrashed()->where('project_id', $projectId)->update(['project_id' => null]);

        $assignmentIds = EquipmentAssignment::withTrashed()->where('project_id', $projectId)->pluck('id');
        EquipmentFuelLog::query()->whereIn('assignment_id', $assignmentIds)->delete();
        EquipmentAssignment::withTrashed()->where('project_id', $projectId)->forceDelete();

        // --- Compliance / BOQ / phases ---
        $complianceItemIds = ProjectComplianceItem::query()->where('project_id', $projectId)->pluck('id');
        ProjectComplianceItemEvent::query()->whereIn('project_compliance_item_id', $complianceItemIds)->delete();
        ProjectComplianceItem::query()->where('project_id', $projectId)->delete();
        ProjectComplianceRule::query()->where('project_id', $projectId)->delete();
        WithholdingTaxRate::query()->where('project_id', $projectId)->delete();

        // Clear boq FKs that may still point at items
        RequisitionItem::query()->whereIn('boq_item_id', function ($q) use ($projectId) {
            $q->select('boq_items.id')
                ->from('boq_items')
                ->join('boq_sections', 'boq_sections.id', '=', 'boq_items.section_id')
                ->where('boq_sections.project_id', $projectId);
        })->update(['boq_item_id' => null]);

        $sectionIds = BoqSection::withTrashed()->where('project_id', $projectId)->pluck('id');
        BoqItem::withTrashed()->whereIn('section_id', $sectionIds)->forceDelete();
        BoqSection::withTrashed()->where('project_id', $projectId)->forceDelete();
        BoqRevision::query()->where('project_id', $projectId)->delete();

        ProjectPhase::withTrashed()->where('project_id', $projectId)->forceDelete();

        StockLocation::withTrashed()->where('project_id', $projectId)->forceDelete();

        RecipientAttendance::query()->where('project_id', $projectId)->delete();
        $project->recipients()->detach();

        WorkflowConfig::withTrashed()->where('project_id', $projectId)->forceDelete();
    }
}
