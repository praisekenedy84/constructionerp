<?php

namespace App\Services;

use App\Enums\ApprovalStepStatus;
use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Enums\FulfillmentType;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionResourceType;
use App\Enums\RequisitionStatus;
use App\Exceptions\BOQLimitExceededException;
use App\Exceptions\ClosingRequiresDocumentException;
use App\Exceptions\InsufficientCashException;
use App\Exceptions\InvalidTransitionException;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\CashDisbursement;
use App\Models\Expense;
use App\Models\InventoryItem;
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
     *     project_id?: int|null,
     *     boq_item_id?: int|null,
     *     department: string,
     *     department_id?: int|null,
     *     resource_type: RequisitionResourceType|string,
     *     requestor_id: int,
     *     recipient_name?: string|null,
     *     recipient_position?: string|null,
     *     fulfillment_type: FulfillmentType|string,
     *     items: array<int, array<string, mixed>>,
     * }  $data
     */
    public function create(array $data): Requisition
    {
        return DB::transaction(function () use ($data) {
            $originalAmount = '0';
            $projectId = isset($data['project_id']) && $data['project_id'] !== ''
                ? (int) $data['project_id']
                : null;
            $headerBoqId = $projectId ? ($data['boq_item_id'] ?? null) : null;

            $resourceType = RequisitionResourceType::Other;

            $fulfillmentType = $data['fulfillment_type'] instanceof FulfillmentType
                ? $data['fulfillment_type']
                : FulfillmentType::from($data['fulfillment_type']);

            $addressedTo = $this->resolveAddressedTo($data['addressed_to'] ?? null, $fulfillmentType);

            $normalizedItems = [];

            foreach ($data['items'] as $item) {
                $normalized = $this->normalizeItem($item, $headerBoqId);
                $originalAmount = bcadd($originalAmount, $normalized['line_total'], 2);
                $normalizedItems[] = $normalized;
            }

            $requisition = Requisition::create([
                'requisition_no' => $this->generateRequisitionNo(),
                'project_id' => $projectId,
                'boq_item_id' => $headerBoqId,
                'department' => $data['department'],
                'department_id' => $data['department_id'] ?? null,
                'requisition_category_id' => $data['requisition_category_id'] ?? null,
                'resource_type' => $resourceType,
                'requestor_id' => $data['requestor_id'],
                'recipient_name' => $data['recipient_name'] ?? null,
                'recipient_position' => $data['recipient_position'] ?? null,
                'status' => RequisitionStatus::Draft,
                'fulfillment_type' => $fulfillmentType,
                'addressed_to' => $addressedTo,
                'original_amount' => $originalAmount,
            ]);

            foreach ($normalizedItems as $item) {
                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    ...$item,
                ]);
            }

            return $requisition->load('items');
        });
    }

    /**
     * @param  array{
     *     project_id?: int|null,
     *     boq_item_id?: int|null,
     *     department: string,
     *     department_id?: int|null,
     *     resource_type: RequisitionResourceType|string,
     *     recipient_name?: string|null,
     *     recipient_position?: string|null,
     *     fulfillment_type: FulfillmentType|string,
     *     items: array<int, array<string, mixed>>,
     * }  $data
     */
    public function update(Requisition $requisition, array $data): Requisition
    {
        if (! $this->isEditable($requisition)) {
            throw new \InvalidArgumentException(
                'Only draft or rejected requisitions can be edited.'
            );
        }

        return DB::transaction(function () use ($requisition, $data) {
            $originalAmount = '0';
            $projectId = array_key_exists('project_id', $data)
                ? (isset($data['project_id']) && $data['project_id'] !== '' ? (int) $data['project_id'] : null)
                : $requisition->project_id;
            $headerBoqId = $projectId ? ($data['boq_item_id'] ?? null) : null;

            $resourceType = RequisitionResourceType::Other;

            $fulfillmentType = $data['fulfillment_type'] instanceof FulfillmentType
                ? $data['fulfillment_type']
                : FulfillmentType::from($data['fulfillment_type']);

            $addressedTo = $this->resolveAddressedTo($data['addressed_to'] ?? null, $fulfillmentType);

            $normalizedItems = [];

            foreach ($data['items'] as $item) {
                $normalized = $this->normalizeItem($item, $headerBoqId);
                $originalAmount = bcadd($originalAmount, $normalized['line_total'], 2);
                $normalizedItems[] = $normalized;
            }

            $requisition->update([
                'project_id' => $projectId,
                'boq_item_id' => $headerBoqId,
                'department' => $data['department'],
                'department_id' => $data['department_id'] ?? $requisition->department_id,
                'requisition_category_id' => $data['requisition_category_id'] ?? $requisition->requisition_category_id,
                'resource_type' => $resourceType,
                'recipient_name' => array_key_exists('recipient_name', $data)
                    ? $data['recipient_name']
                    : $requisition->recipient_name,
                'recipient_position' => array_key_exists('recipient_position', $data)
                    ? $data['recipient_position']
                    : $requisition->recipient_position,
                'fulfillment_type' => $fulfillmentType,
                'addressed_to' => $addressedTo,
                'original_amount' => $originalAmount,
                'amended_amount' => null,
                'status' => RequisitionStatus::Draft,
            ]);

            $requisition->items()->delete();

            foreach ($normalizedItems as $item) {
                RequisitionItem::create([
                    'requisition_id' => $requisition->id,
                    ...$item,
                ]);
            }

            return $requisition->fresh(['items']);
        });
    }

    public function isEditable(Requisition $requisition): bool
    {
        $status = $requisition->status instanceof RequisitionStatus
            ? $requisition->status
            : RequisitionStatus::from((string) $requisition->status);

        return in_array($status, [RequisitionStatus::Draft, RequisitionStatus::Rejected], true);
    }

    /**
     * Soft-delete a wrongly created requisition, reversing budget/BOQ/cash effects.
     */
    public function destroy(Requisition $req, User $actor): void
    {
        $status = $req->status instanceof RequisitionStatus
            ? $req->status
            : RequisitionStatus::from((string) $req->status);

        if ($status === RequisitionStatus::Closed) {
            throw new \InvalidArgumentException('Closed requisitions cannot be deleted. Re-open is not supported.');
        }

        DB::transaction(function () use ($req, $actor, $status) {
            $req = Requisition::with(['items', 'cashDisbursements'])->lockForUpdate()->findOrFail($req->id);

            if (in_array($status, [RequisitionStatus::Approved, RequisitionStatus::Amended], true)) {
                $this->onCancelled($req, $actor);
            }

            if ($status === RequisitionStatus::UnderReview) {
                ApprovalStep::where('requisition_id', $req->id)
                    ->where('status', ApprovalStepStatus::Pending)
                    ->update([
                        'status' => ApprovalStepStatus::Rejected,
                        'resolved_at' => now(),
                    ]);
            }

            if ($status === RequisitionStatus::Fulfilled) {
                $this->reverseFulfillment($req, $actor);
            }

            RequisitionStatusHistory::create([
                'requisition_id' => $req->id,
                'from_status' => $status->value,
                'to_status' => 'cancelled',
                'actor_id' => $actor->id,
                'comment' => 'Requisition deleted',
                'created_at' => now(),
            ]);

            $req->delete();
        });
    }

    private function reverseFulfillment(Requisition $req, User $actor): void
    {
        $qty = $this->sumItemQuantities($req);

        if ($req->boq_item_id) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
            $boqItem->decrement('consumed_qty', $qty);
        }

        foreach ($req->cashDisbursements as $disbursement) {
            $disbursement->delete();
        }

        Expense::query()
            ->where('requisition_id', $req->id)
            ->get()
            ->each(function (Expense $expense) {
                $expense->cashDisbursements()->delete();
                $expense->delete();
            });

        // Stock / non-cash project requisitions post budget on approve; reverse that after fulfill.
        if (! $this->spendsCashFloat($req) && $req->project_id) {
            $amount = (string) ($req->amended_amount ?? $req->original_amount);
            $this->budgetService->createTransaction($req->project_id, [
                'type' => $req->amended_amount
                    ? BudgetTransactionType::AmendedRequisition
                    : BudgetTransactionType::ApprovedRequisition,
                'amount' => bcsub('0', $amount, 2),
                'boq_item_id' => $req->boq_item_id,
                'reference_entity_type' => 'requisition_deletion',
                'reference_entity_id' => $req->id,
                'reason' => 'Deletion reversal after fulfillment',
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{
     *     boq_item_id: int|null,
     *     inventory_item_id: int|null,
     *     description: string,
     *     unit: string|null,
     *     quantity: string,
     *     unit_cost: string,
     *     line_total: string,
     *     details: array<string, mixed>|null,
     * }
     */
    private function normalizeItem(array $item, ?int $headerBoqId): array
    {
        $inventoryItemId = $item['inventory_item_id'] ?? null;
        $description = trim((string) ($item['description'] ?? ''));
        $unit = $item['unit'] ?? null;

        if ($inventoryItemId) {
            $inventoryItem = InventoryItem::findOrFail($inventoryItemId);
            if ($description === '') {
                $description = $inventoryItem->name;
            }
            if (! $unit) {
                $unit = $inventoryItem->unit;
            }
        }

        return $this->normalizeQuantityCostItem([
            'boq_item_id' => $item['boq_item_id'] ?? $headerBoqId,
            'inventory_item_id' => $inventoryItemId,
            'description' => $description,
        ], $item, $unit);
    }

    /**
     * @param  array{boq_item_id: int|null, inventory_item_id: int|null, description: string}  $base
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function normalizeQuantityCostItem(array $base, array $item, ?string $unit): array
    {
        $qty = bcadd((string) $item['quantity'], '0', 3);
        $unitCost = bcadd((string) $item['unit_cost'], '0', 2);

        return [
            ...$base,
            'unit' => $unit,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'line_total' => bcmul($qty, $unitCost, 2),
            'details' => null,
        ];
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

        $qty = $this->sumItemQuantities($req);
        $amount = (string) $req->original_amount;
        $this->assertCashAvailable($req, $amount, $actor, $opts);

        if ($req->boq_item_id) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);

            if (bccomp($qty, (string) $boqItem->available_qty, 3) === 1) {
                if (! $this->canBypassLimit($actor, $opts)) {
                    throw new BOQLimitExceededException($boqItem, $qty);
                }
            }

            $boqItem->increment('reserved_qty', $qty);
            $boqItem->increment('approved_qty', $qty);
        }

        // Cash / supplier payment spends finance cash float (already drawn from
        // project or organization budget via fund approval). Only non-cash
        // project fulfillments post a project budget commitment.
        if (! $this->spendsCashFloat($req) && $req->project_id) {
            $this->budgetService->createTransaction($req->project_id, [
                'type' => BudgetTransactionType::ApprovedRequisition,
                'amount' => $amount,
                'boq_item_id' => $req->boq_item_id,
                'reference_entity_type' => 'requisition',
                'reference_entity_id' => $req->id,
                'created_by' => $actor->id,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $opts
     * @return array{amended_amount: string, amendment_items: array{before: list<array<string, mixed>>, after: list<array<string, mixed>>}}
     */
    public function onAmended(Requisition $req, User $actor, array $opts = []): array
    {
        if ($req->requestor_id === $actor->id && ! $actor->isSuperUser()) {
            throw new AuthorizationException('You cannot amend your own requisition.');
        }

        $itemsInput = $opts['items'] ?? [];
        if (! is_array($itemsInput) || $itemsInput === [] || empty($opts['amendment_reason'])) {
            throw new \InvalidArgumentException(
                'Amended line items and amendment_reason are required'
            );
        }

        $req->loadMissing('items');

        $before = $req->items->map(fn (RequisitionItem $item) => [
            'id' => $item->id,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_cost' => (string) $item->unit_cost,
            'line_total' => (string) $item->line_total,
        ])->values()->all();

        foreach ($req->items as $existing) {
            if ($existing->original_quantity === null) {
                $existing->forceFill([
                    'original_quantity' => $existing->quantity,
                    'original_unit_cost' => $existing->unit_cost,
                    'original_line_total' => $existing->line_total,
                    'original_description' => $existing->description,
                ])->save();
            }
        }

        $amendedTotal = '0';
        $keptIds = [];
        $headerBoqId = $req->boq_item_id;

        foreach ($itemsInput as $itemData) {
            $qty = bcadd((string) ($itemData['quantity'] ?? '0'), '0', 3);
            $unitCost = bcadd((string) ($itemData['unit_cost'] ?? '0'), '0', 2);
            $lineTotal = bcmul($qty, $unitCost, 2);
            $description = trim((string) ($itemData['description'] ?? ''));

            if ($description === '' || bccomp($qty, '0', 3) !== 1) {
                throw new \InvalidArgumentException('Each amended line needs a description and quantity greater than zero.');
            }

            $itemId = isset($itemData['id']) ? (int) $itemData['id'] : null;

            if ($itemId) {
                $existing = $req->items->firstWhere('id', $itemId);
                if (! $existing) {
                    throw new \InvalidArgumentException("Unknown requisition item #{$itemId}.");
                }

                $details = $existing->details;
                if (is_array($details) && array_key_exists('estimated_amount', $details)) {
                    $details['estimated_amount'] = $lineTotal;
                }

                $existing->update([
                    'description' => $description,
                    'unit' => $itemData['unit'] ?? $existing->unit,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'details' => $details,
                ]);

                $keptIds[] = $existing->id;
            } else {
                $created = RequisitionItem::create([
                    'requisition_id' => $req->id,
                    'boq_item_id' => $itemData['boq_item_id'] ?? $headerBoqId,
                    'inventory_item_id' => $itemData['inventory_item_id'] ?? null,
                    'description' => $description,
                    'unit' => $itemData['unit'] ?? null,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                    'original_quantity' => '0',
                    'original_unit_cost' => '0',
                    'original_line_total' => '0',
                    'original_description' => null,
                    'details' => null,
                ]);
                $keptIds[] = $created->id;
            }

            $amendedTotal = bcadd($amendedTotal, $lineTotal, 2);
        }

        $req->items()
            ->whereNotIn('id', $keptIds)
            ->get()
            ->each(fn (RequisitionItem $item) => $item->delete());

        $req->refresh()->load('items');

        $this->assertCashAvailable($req, $amendedTotal, $actor, $opts);

        $amendedQty = $this->sumItemQuantities($req);

        if ($req->boq_item_id) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);

            if (bccomp($amendedQty, (string) $boqItem->available_qty, 3) === 1) {
                if (! $this->canBypassLimit($actor, $opts)) {
                    throw new BOQLimitExceededException($boqItem, $amendedQty);
                }
            }

            $boqItem->increment('reserved_qty', $amendedQty);
            $boqItem->increment('approved_qty', $amendedQty);
        }

        $req->update(['amended_amount' => $amendedTotal]);

        if (! $this->spendsCashFloat($req) && $req->project_id) {
            $this->budgetService->createTransaction($req->project_id, [
                'type' => BudgetTransactionType::AmendedRequisition,
                'amount' => $amendedTotal,
                'boq_item_id' => $req->boq_item_id,
                'reference_entity_type' => 'requisition',
                'reference_entity_id' => $req->id,
                'created_by' => $actor->id,
            ]);
        }

        $after = $req->items->map(fn (RequisitionItem $item) => [
            'id' => $item->id,
            'description' => $item->description,
            'unit' => $item->unit,
            'quantity' => (string) $item->quantity,
            'unit_cost' => (string) $item->unit_cost,
            'line_total' => (string) $item->line_total,
            'original_quantity' => $item->original_quantity !== null ? (string) $item->original_quantity : null,
            'original_unit_cost' => $item->original_unit_cost !== null ? (string) $item->original_unit_cost : null,
            'original_line_total' => $item->original_line_total !== null ? (string) $item->original_line_total : null,
        ])->values()->all();

        return [
            'amended_amount' => $amendedTotal,
            'amendment_items' => [
                'before' => $before,
                'after' => $after,
            ],
        ];
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

        if ($req->boq_item_id) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
            $boqItem->decrement('reserved_qty', $qty);
            $boqItem->increment('consumed_qty', $qty);
        }

        $disbursement = null;

        if ($req->isAddressedToStorekeeper()) {
            $this->fulfillmentService->fulfillStock($req, $actor, $opts);
        } else {
            $disbursement = $this->fulfillmentService->fulfillCash($req, $actor, $amount, $opts);
        }

        $this->recordExpenseFromRequisition($req, $actor, $disbursement);
    }

    /**
     * Fulfilled requisitions become expenses:
     * project-scoped → direct; organization-wide → overhead (indirect).
     */
    private function recordExpenseFromRequisition(
        Requisition $req,
        User $actor,
        ?CashDisbursement $disbursement = null,
    ): Expense {
        $req->loadMissing(['category', 'items']);

        $amount = (string) ($req->amended_amount ?? $req->original_amount);
        $category = $req->isOrganizationWide()
            ? ExpenseCategory::Indirect
            : ExpenseCategory::Direct;

        $lineDescriptions = $req->items
            ->pluck('description')
            ->filter()
            ->take(3)
            ->implode('; ');

        if ($req->items->count() > 3) {
            $lineDescriptions .= '…';
        }

        $description = trim($lineDescriptions) !== ''
            ? trim($lineDescriptions).' ['.$req->requisition_no.']'
            : 'Requisition '.$req->requisition_no;

        $expense = Expense::create([
            'project_id' => $req->project_id,
            'boq_item_id' => $req->boq_item_id,
            'requisition_id' => $req->id,
            'category' => $category,
            'sub_type' => $req->category?->name
                ?? ($req->department !== '' ? $req->department : 'Requisition'),
            'activity_ref' => $req->requisition_no,
            'amount' => $amount,
            'description' => $description,
            'expense_date' => now()->toDateString(),
            'recorded_by' => $actor->id,
        ]);

        if ($disbursement) {
            $disbursement->update(['expense_id' => $expense->id]);
        }

        return $expense;
    }

    private function onCancelled(Requisition $req, User $actor): void
    {
        $qty = $this->sumItemQuantities($req);
        $amount = (string) ($req->amended_amount ?? $req->original_amount);
        $type = $req->amended_amount
            ? BudgetTransactionType::AmendedRequisition
            : BudgetTransactionType::ApprovedRequisition;

        if ($req->boq_item_id) {
            BoqItem::lockForUpdate()->findOrFail($req->boq_item_id)
                ->decrement('reserved_qty', $qty);
        }

        if (! $this->spendsCashFloat($req) && $req->project_id) {
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
    }

    private function spendsCashFloat(Requisition $req): bool
    {
        return $req->isAddressedToFinance();
    }

    private function resolveAddressedTo(
        RequisitionAddressedTo|string|null $addressedTo,
        FulfillmentType $fulfillmentType,
    ): RequisitionAddressedTo {
        if ($addressedTo instanceof RequisitionAddressedTo) {
            return $addressedTo;
        }

        if (is_string($addressedTo) && $addressedTo !== '') {
            return RequisitionAddressedTo::from($addressedTo);
        }

        return $fulfillmentType === FulfillmentType::StockIssue
            ? RequisitionAddressedTo::Storekeeper
            : RequisitionAddressedTo::Finance;
    }

    private function onClosed(Requisition $req): void
    {
        if ($req->attachments()->count() === 0) {
            throw new ClosingRequiresDocumentException($req->id);
        }
    }

    private function assertCanTransition(User $actor, Requisition $req, string $toStatus): void
    {
        if ($toStatus === 'under_review' && ! $req->isOwnedBy($actor) && ! $actor->isSuperUser()) {
            throw new AuthorizationException(
                'Only the author or an administrator can publish this requisition for approval.'
            );
        }

        $permission = match ($toStatus) {
            'under_review' => ['requisitions', 'publish'],
            'fulfilled' => ['requisitions', 'fulfill'],
            'cancelled' => ['requisitions', 'cancel'],
            'closed' => ['requisitions', 'close'],
            default => null,
        };

        if ($permission && ! $actor->hasModulePermission($permission[0], $permission[1])) {
            throw new AuthorizationException('You do not have permission for this transition.');
        }
    }

    private function assertCashAvailable(Requisition $req, string $amount, User $actor, array $opts): void
    {
        if (! $this->spendsCashFloat($req)) {
            return;
        }

        $position = $this->reportService->cashPosition(
            $req->isOrganizationWide()
                ? ['scope' => 'organization']
                : ['project_id' => $req->project_id]
        );
        $cashOnHand = (string) $position['cash_on_hand'];

        // Cash already promised to other approved/amended cash requests is not free to re-approve.
        $committedQuery = Requisition::query()
            ->where('id', '!=', $req->id)
            ->whereIn('status', [RequisitionStatus::Approved, RequisitionStatus::Amended])
            ->where(function ($query) {
                $query->where('addressed_to', RequisitionAddressedTo::Finance->value)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('addressed_to')
                            ->whereIn('fulfillment_type', [
                                FulfillmentType::CashDisbursement->value,
                                FulfillmentType::DirectSupplierPayment->value,
                            ]);
                    });
            });

        if ($req->isOrganizationWide()) {
            $committedQuery->whereNull('project_id');
        } else {
            $committedQuery->where('project_id', $req->project_id);
        }

        $committedElsewhere = '0';
        foreach ($committedQuery->get() as $other) {
            $committedElsewhere = bcadd(
                $committedElsewhere,
                (string) ($other->amended_amount ?? $other->original_amount),
                2
            );
        }

        $available = bcsub($cashOnHand, $committedElsewhere, 2);

        if (bccomp($available, $amount, 2) >= 0) {
            return;
        }

        if ($this->canBypassLimit($actor, $opts)) {
            return;
        }

        $this->notifyRole('Finance Manager', 'cash_shortfall', [
            'requisition_id' => $req->id,
            'requisition_no' => $req->requisition_no,
            'required' => $amount,
            'available' => $available,
            'cash_on_hand' => $cashOnHand,
            'committed' => $committedElsewhere,
            'scope' => $req->isOrganizationWide() ? 'organization' : 'project',
        ]);

        throw new InsufficientCashException($amount, $available);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function canBypassLimit(User $actor, array $opts): bool
    {
        if ($actor->isSuperUser()) {
            return true;
        }

        return ($opts['override'] ?? false) && $actor->canOverrideLimits();
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
            $total = bcadd($total, (string) $item->quantity, 3);
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
