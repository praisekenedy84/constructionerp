<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\BudgetTransactionType;
use App\Enums\FulfillmentType;
use App\Enums\RequisitionStatus;
use App\Exceptions\BOQLimitExceededException;
use App\Exceptions\ClosingRequiresDocumentException;
use App\Exceptions\InsufficientCashException;
use App\Exceptions\InvalidTransitionException;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\Notification;
use App\Models\Requisition;
use App\Models\RequisitionAttachment;
use App\Models\RequisitionItem;
use App\Models\RequisitionStatusHistory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class RequisitionService
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly ReportService $reportService,
    ) {}

    /**
     * @param  array{
     *     project_id: int,
     *     boq_item_id: int,
     *     department: string,
     *     requestor_id: int,
     *     fulfillment_type: FulfillmentType|string,
     *     items: array<int, array{
     *         boq_item_id: int,
     *         description: string,
     *         quantity: string|float,
     *         unit_cost: string|float,
     *     }>,
     * }  $data
     */
    public function create(array $data): Requisition
    {
        return DB::transaction(function () use ($data) {
            $originalAmount = '0';

            foreach ($data['items'] as $item) {
                $qty = bcadd((string) $item['quantity'], '0', 4);
                $unitCost = bcadd((string) $item['unit_cost'], '0', 2);
                $originalAmount = bcadd($originalAmount, bcmul($qty, $unitCost, 2), 2);
            }

            $fulfillmentType = $data['fulfillment_type'] instanceof FulfillmentType
                ? $data['fulfillment_type']
                : FulfillmentType::from($data['fulfillment_type']);

            $requisition = Requisition::create([
                'requisition_no' => $this->generateRequisitionNo(),
                'project_id' => $data['project_id'],
                'boq_item_id' => $data['boq_item_id'],
                'department' => $data['department'],
                'requestor_id' => $data['requestor_id'],
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => $fulfillmentType,
                'original_amount' => $originalAmount,
            ]);

            foreach ($data['items'] as $item) {
                $qty = bcadd((string) $item['quantity'], '0', 4);
                $unitCost = bcadd((string) $item['unit_cost'], '0', 2);

                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    'boq_item_id' => $item['boq_item_id'],
                    'description' => $item['description'],
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_total' => bcmul($qty, $unitCost, 2),
                ]);
            }

            return $requisition->load('items');
        });
    }

    public function addAttachment(
        Requisition $req,
        UploadedFile $file,
        string $documentType,
        User $actor,
    ): RequisitionAttachment {
        $path = $file->store("requisitions/{$req->id}", 'public');

        return RequisitionAttachment::create([
            'requisition_id' => $req->id,
            'file_url' => Storage::disk('public')->url($path),
            'document_type' => $documentType,
            'uploaded_by' => $actor->id,
            'created_at' => now(),
        ]);
    }

    public function transition(
        Requisition $req,
        string $toStatus,
        User $actor,
        array $opts = [],
    ): Requisition {
        if ($toStatus === 'submitted') {
            $toStatus = 'under_review';
        }

        $currentStatus = $req->status instanceof RequisitionStatus
            ? $req->status->value
            : (string) $req->status;

        if (in_array($toStatus, ['approved', 'amended', 'rejected'], true)) {
            throw new InvalidTransitionException($currentStatus, $toStatus);
        }

        $allowed = [
            'draft' => ['under_review'],
            'submitted' => ['under_review'],
            'under_review' => [],
            'approved' => ['fulfilled', 'cancelled'],
            'amended' => ['fulfilled', 'cancelled'],
            'fulfilled' => ['closed'],
            'rejected' => [],
            'cancelled' => [],
            'closed' => [],
        ];

        if (! in_array($toStatus, $allowed[$currentStatus] ?? [], true)) {
            throw new InvalidTransitionException($currentStatus, $toStatus);
        }

        $this->assertCanTransition($actor, $req, $toStatus);

        DB::transaction(function () use ($req, $toStatus, $actor, $opts, $currentStatus) {
            $req->load('items');

            if ($toStatus === 'under_review' && $currentStatus === 'draft') {
                app(ApprovalService::class)->createSteps($req);
                $this->notifyRole('Project Manager', 'requisition_submitted', [
                    'requisition_id' => $req->id,
                    'requisition_no' => $req->requisition_no,
                    'project_id' => $req->project_id,
                ]);
            }

            RequisitionStatusHistory::create([
                'requisition_id' => $req->id,
                'from_status' => $currentStatus,
                'to_status' => $toStatus,
                'actor_id' => $actor->id,
                'comment' => $opts['comment'] ?? null,
                'amendment_reason' => $opts['amendment_reason'] ?? null,
                'original_amount' => $req->original_amount,
                'amended_amount' => $opts['amended_amount'] ?? null,
                'variance' => isset($opts['amended_amount'])
                    ? bcsub((string) $req->original_amount, (string) $opts['amended_amount'], 2)
                    : null,
                'created_at' => now(),
            ]);

            match ($toStatus) {
                'approved' => $this->onApproved($req, $actor, $opts),
                'amended' => $this->onAmended($req, $actor, $opts),
                'fulfilled' => $this->onFulfilled($req, $actor, $opts),
                'cancelled' => $this->onCancelled($req, $actor),
                'closed' => $this->onClosed($req),
                default => null,
            };

            $req->update(['status' => RequisitionStatus::from($toStatus)]);

            $this->notifyRequestor($req, $toStatus, $actor);
        });

        return $req->fresh(['items', 'statusHistories', 'approvalSteps']);
    }

    public function onApproved(Requisition $req, User $actor, array $opts = []): void
    {
        if ($req->requestor_id === $actor->id && ! $actor->isSuperUser()) {
            throw new AuthorizationException('You cannot approve your own requisition.');
        }

        $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
        $qty = $this->sumItemQuantities($req);

        if (bccomp($qty, (string) $boqItem->available_qty, 4) === 1) {
            if (! ($opts['override'] ?? false) || ! $actor->canOverrideLimits()) {
                throw new BOQLimitExceededException($boqItem, $qty);
            }
        }

        $amount = (string) $req->original_amount;
        $this->assertCashAvailable($req, $amount, $actor, $opts);

        $boqItem->increment('reserved_qty', $qty);
        $boqItem->increment('approved_qty', $qty);

        $this->budgetService->createTransaction($req->project_id, [
            'type' => BudgetTransactionType::ApprovedRequisition,
            'amount' => $amount,
            'boq_item_id' => $req->boq_item_id,
            'reference_entity_type' => 'requisition',
            'reference_entity_id' => $req->id,
            'created_by' => $actor->id,
        ]);
    }

    public function onAmended(Requisition $req, User $actor, array $opts = []): void
    {
        if ($req->requestor_id === $actor->id && ! $actor->isSuperUser()) {
            throw new AuthorizationException('You cannot amend your own requisition.');
        }

        if (empty($opts['amended_amount']) || empty($opts['amendment_reason'])) {
            throw new \InvalidArgumentException(
                'amended_amount and amendment_reason are required'
            );
        }

        $totalQty = $this->sumItemQuantities($req);
        $amendedQty = bcmul(
            $totalQty,
            bcdiv((string) $opts['amended_amount'], (string) $req->original_amount, 4),
            4
        );

        $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);

        if (bccomp($amendedQty, (string) $boqItem->available_qty, 4) === 1) {
            if (! ($opts['override'] ?? false) || ! $actor->canOverrideLimits()) {
                throw new BOQLimitExceededException($boqItem, $amendedQty);
            }
        }

        $this->assertCashAvailable($req, (string) $opts['amended_amount'], $actor, $opts);

        $boqItem->increment('reserved_qty', $amendedQty);
        $req->update(['amended_amount' => $opts['amended_amount']]);

        $this->budgetService->createTransaction($req->project_id, [
            'type' => BudgetTransactionType::AmendedRequisition,
            'amount' => (string) $opts['amended_amount'],
            'boq_item_id' => $req->boq_item_id,
            'reference_entity_type' => 'requisition',
            'reference_entity_id' => $req->id,
            'created_by' => $actor->id,
        ]);
    }

    public function onRejected(Requisition $req): void
    {
        ApprovalStep::where('requisition_id', $req->id)
            ->where('status', ApprovalStepStatus::Pending)
            ->update([
                'status' => ApprovalStepStatus::Rejected,
                'resolved_at' => now(),
            ]);
    }

    private function onFulfilled(Requisition $req, User $actor, array $opts): void
    {
        $qty = $this->sumItemQuantities($req);
        $amount = (string) ($req->amended_amount ?? $req->original_amount);

        $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
        $boqItem->decrement('reserved_qty', $qty);
        $boqItem->increment('consumed_qty', $qty);

        $fulfillmentType = $req->fulfillment_type instanceof FulfillmentType
            ? $req->fulfillment_type->value
            : (string) $req->fulfillment_type;

        match ($fulfillmentType) {
            FulfillmentType::CashDisbursement->value,
            FulfillmentType::DirectSupplierPayment->value
                => $this->fulfillmentService->fulfillCash($req, $actor, $amount, $opts),
            FulfillmentType::StockIssue->value
                => $this->fulfillmentService->fulfillStock($req, $actor, $opts),
            default => throw new \InvalidArgumentException("Unknown fulfillment type: {$fulfillmentType}"),
        };
    }

    private function onCancelled(Requisition $req, User $actor): void
    {
        $qty = $this->sumItemQuantities($req);
        $amount = (string) ($req->amended_amount ?? $req->original_amount);
        $type = $req->amended_amount
            ? BudgetTransactionType::AmendedRequisition
            : BudgetTransactionType::ApprovedRequisition;

        BoqItem::lockForUpdate()->findOrFail($req->boq_item_id)
            ->decrement('reserved_qty', $qty);

        $this->budgetService->createTransaction($req->project_id, [
            'type' => $type,
            'amount' => bcsub('0', $amount, 2),
            'boq_item_id' => $req->boq_item_id,
            'reference_entity_type' => 'requisition_cancellation',
            'reference_entity_id' => $req->id,
            'reason' => 'Cancellation reversal',
            'created_by' => $actor->id,
        ]);
    }

    private function onClosed(Requisition $req): void
    {
        if ($req->attachments()->count() === 0) {
            throw new ClosingRequiresDocumentException($req->id);
        }
    }

    private function assertCanTransition(User $actor, Requisition $req, string $toStatus): void
    {
        if ($actor->isSuperUser()) {
            return;
        }

        $permission = match ($toStatus) {
            'under_review' => ['requisitions', 'update'],
            'fulfilled' => ['requisitions', 'fulfill'],
            'cancelled', 'closed' => ['requisitions', 'update'],
            default => null,
        };

        if ($permission && ! $actor->hasModulePermission($permission[0], $permission[1])) {
            throw new AuthorizationException('You do not have permission for this transition.');
        }
    }

    private function assertCashAvailable(Requisition $req, string $amount, User $actor, array $opts): void
    {
        $fulfillmentType = $req->fulfillment_type instanceof FulfillmentType
            ? $req->fulfillment_type
            : FulfillmentType::from((string) $req->fulfillment_type);

        if (! in_array($fulfillmentType, [FulfillmentType::CashDisbursement, FulfillmentType::DirectSupplierPayment], true)) {
            return;
        }

        $cashOnHand = $this->reportService->cashPosition(['project_id' => $req->project_id])['cash_on_hand'];

        if (bccomp($cashOnHand, $amount, 2) >= 0) {
            return;
        }

        if (($opts['override'] ?? false) && $actor->canOverrideLimits()) {
            return;
        }

        $this->notifyRole('Finance Manager', 'cash_shortfall', [
            'requisition_id' => $req->id,
            'requisition_no' => $req->requisition_no,
            'required' => $amount,
            'available' => $cashOnHand,
        ]);

        throw new InsufficientCashException($amount, $cashOnHand);
    }

    private function notifyRequestor(Requisition $req, string $toStatus, User $actor): void
    {
        if (! $req->requestor_id) {
            return;
        }

        Notification::create([
            'user_id' => $req->requestor_id,
            'type' => 'requisition_status_changed',
            'data' => [
                'requisition_id' => $req->id,
                'requisition_no' => $req->requisition_no,
                'status' => $toStatus,
                'actor_id' => $actor->id,
            ],
            'created_at' => now(),
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function notifyRole(string $roleName, string $type, array $data): void
    {
        User::role($roleName)->each(function (User $user) use ($type, $data) {
            Notification::create([
                'user_id' => $user->id,
                'type' => $type,
                'data' => $data,
                'created_at' => now(),
            ]);
        });
    }

    private function sumItemQuantities(Requisition $req): string
    {
        $total = '0';

        foreach ($req->items as $item) {
            $total = bcadd($total, (string) $item->quantity, 4);
        }

        return $total;
    }

    private function generateRequisitionNo(): string
    {
        $year = now()->year;
        $prefix = "REQ-{$year}-";

        $last = Requisition::where('requisition_no', 'like', "{$prefix}%")
            ->orderByDesc('requisition_no')
            ->value('requisition_no');

        $sequence = 1;

        if ($last && preg_match('/REQ-\d{4}-(\d{5})$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
