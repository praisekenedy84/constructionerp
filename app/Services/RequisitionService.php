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
use App\Models\RequisitionRecipient;
use App\Models\RequisitionStatusHistory;
use App\Models\User;
use App\Support\OrganizationFundUse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RequisitionService
{
    public function __construct(
        private readonly BudgetService $budgetService,
        private readonly FulfillmentService $fulfillmentService,
        private readonly ReportService $reportService,
        private readonly MoneyAccountService $moneyAccountService,
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
     *     position_id?: int|null,
     *     fulfillment_type: FulfillmentType|string,
     *     items: array<int, array<string, mixed>>,
     * }  $data
     */
    public function create(array $data): Requisition
    {
        $attempts = 0;

        while (true) {
            try {
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
                        'recipient_id' => $data['recipient_id'] ?? null,
                        'recipient_name' => $data['recipient_name'] ?? null,
                        'recipient_position' => $data['recipient_position'] ?? null,
                        'position_id' => $data['position_id'] ?? null,
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

                    $this->syncCategories($requisition, $data['requisition_category_ids'] ?? []);
                    $this->syncRecipients($requisition, $data['recipients'] ?? []);

                    return $requisition->load(['items', 'recipients', 'categories']);
                });
            } catch (QueryException $e) {
                $attempts++;

                if ($attempts >= 5 || ! $this->isRequisitionNoUniqueViolation($e)) {
                    throw $e;
                }
            }
        }
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
     *     position_id?: int|null,
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
                'recipient_id' => array_key_exists('recipient_id', $data)
                    ? $data['recipient_id']
                    : $requisition->recipient_id,
                'recipient_name' => array_key_exists('recipient_name', $data)
                    ? $data['recipient_name']
                    : $requisition->recipient_name,
                'recipient_position' => array_key_exists('recipient_position', $data)
                    ? $data['recipient_position']
                    : $requisition->recipient_position,
                'position_id' => array_key_exists('position_id', $data)
                    ? $data['position_id']
                    : $requisition->position_id,
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

            if (array_key_exists('requisition_category_ids', $data)) {
                $this->syncCategories($requisition, $data['requisition_category_ids'] ?? []);
            }

            if (array_key_exists('recipients', $data)) {
                $this->syncRecipients($requisition, $data['recipients'] ?? []);
            }

            return $requisition->fresh(['items', 'recipients', 'categories']);
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
        if ($status === RequisitionStatus::PartiallyFulfilled) {
            throw new \InvalidArgumentException('Partially fulfilled requisitions cannot be deleted.');
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

        // Project requisitions (cash or stock) post budget on approve; reverse on delete after fulfill.
        if ($req->project_id) {
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
            'requisition_category_id' => isset($item['requisition_category_id']) && $item['requisition_category_id'] !== ''
                ? (int) $item['requisition_category_id']
                : null,
            'recipient_id' => isset($item['recipient_id']) && $item['recipient_id'] !== ''
                ? (int) $item['recipient_id']
                : null,
            'recipient_name' => array_key_exists('recipient_name', $item)
                ? ($item['recipient_name'] !== null && $item['recipient_name'] !== ''
                    ? trim((string) $item['recipient_name'])
                    : null)
                : null,
            'position_id' => isset($item['position_id']) && $item['position_id'] !== ''
                ? (int) $item['position_id']
                : null,
            'recipient_position' => array_key_exists('recipient_position', $item)
                ? ($item['recipient_position'] !== null && $item['recipient_position'] !== ''
                    ? (string) $item['recipient_position']
                    : null)
                : null,
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
        $days = $this->normalizeOptionalDays($item['days'] ?? null);
        $lineTotal = $this->lineTotalFor($qty, $unitCost, $days);

        $details = [];
        if (isset($item['details']) && is_array($item['details'])) {
            $details = $item['details'];
        }
        if ($days !== null) {
            $details['days'] = $days;
        }

        return [
            ...$base,
            'unit' => $unit,
            'quantity' => $qty,
            'unit_cost' => $unitCost,
            'line_total' => $lineTotal,
            'details' => $details === [] ? null : $details,
        ];
    }

    /**
     * @return non-empty-string|null
     */
    private function normalizeOptionalDays(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $days = bcadd((string) $raw, '0', 3);

        return bccomp($days, '0', 3) === 1 ? $days : null;
    }

    private function lineTotalFor(string $quantity, string $unitCost, ?string $days): string
    {
        $base = bcmul($quantity, $unitCost, 4);
        $multiplier = $days ?? '1';

        return bcmul($base, $multiplier, 2);
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
            'partially_fulfilled' => ['fulfilled'],
            'fulfilled' => ['closed'],
            'rejected' => [],
            'cancelled' => [],
            'closed' => [],
        ];

        if (! in_array($toStatus, $allowed[$currentStatus] ?? [], true)) {
            throw new InvalidTransitionException($currentStatus, $toStatus);
        }

        $this->assertCanTransition($actor, $req, $toStatus);

        $finalStatus = $toStatus;

        DB::transaction(function () use ($req, $toStatus, $actor, $opts, $currentStatus, &$finalStatus) {
            $req->load('items');

            if ($toStatus === 'under_review' && $currentStatus === 'draft') {
                $steps = app(ApprovalService::class)->createSteps($req);
                $this->notifyRole('Project Manager', 'requisition_submitted', [
                    'requisition_id' => $req->id,
                    'requisition_no' => $req->requisition_no,
                    'project_id' => $req->project_id,
                ]);

                // No workflow configured — auto-approve so finance can fulfill.
                if ($steps === []) {
                    $this->autoApproveWithoutWorkflow($req, $actor, $opts);
                    $finalStatus = RequisitionStatus::Approved->value;
                }
            }

            if ($toStatus === 'fulfilled') {
                $finalStatus = $this->onFulfilled($req, $actor, $opts);
            } else {
                match ($toStatus) {
                    'approved' => $this->onApproved($req, $actor, $opts),
                    'amended' => $this->onAmended($req, $actor, $opts),
                    'cancelled' => $this->onCancelled($req, $actor),
                    'closed' => $this->onClosed($req),
                    default => null,
                };
            }

            RequisitionStatusHistory::create([
                'requisition_id' => $req->id,
                'from_status' => $currentStatus,
                'to_status' => $finalStatus,
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

            $req->update(['status' => RequisitionStatus::from($finalStatus)]);

            $this->notifyRequestor($req, $finalStatus, $actor);
        });

        return $req->fresh(['items', 'statusHistories', 'approvalSteps']);
    }

    public function onApproved(Requisition $req, User $actor, array $opts = []): void
    {
        if ($req->requestor_id === $actor->id && ! $actor->isSuperUser()) {
            throw new AuthorizationException('You cannot approve your own requisition.');
        }

        $this->applyApprovalEffects($req, $actor, $opts);
    }

    /**
     * Approve when no WorkflowConfig steps exist (no separate approver to assign).
     * Skips the self-approval guard because there is no alternate approver role.
     *
     * @param  array<string, mixed>  $opts
     */
    private function autoApproveWithoutWorkflow(Requisition $req, User $actor, array $opts = []): void
    {
        $this->applyApprovalEffects($req, $actor, $opts);
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function applyApprovalEffects(Requisition $req, User $actor, array $opts = []): void
    {
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

        // Cash / supplier payment spends the shared Finance Wallet. Project-scoped
        // finance requisitions still commit against the project budget so
        // profitability tracks expenses vs net budget (not cash on hand).
        if ($req->project_id) {
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
            $days = array_key_exists('days', $itemData)
                ? $this->normalizeOptionalDays($itemData['days'] ?? null)
                : null;
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

                // Keep existing days when the amend payload omits the field.
                if (! array_key_exists('days', $itemData)) {
                    $days = $existing->days();
                }

                $lineTotal = $this->lineTotalFor($qty, $unitCost, $days);
                $details = is_array($existing->details) ? $existing->details : [];
                if ($days !== null) {
                    $details['days'] = $days;
                } else {
                    unset($details['days']);
                }
                if (array_key_exists('estimated_amount', $details)) {
                    $details['estimated_amount'] = $lineTotal;
                }
                $details = $details === [] ? null : $details;

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
                $lineTotal = $this->lineTotalFor($qty, $unitCost, $days);
                $details = $days !== null ? ['days' => $days] : null;
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
                    'details' => $details,
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

        if ($req->project_id) {
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

    private function onFulfilled(Requisition $req, User $actor, array $opts): string
    {
        $req = Requisition::with('items')->lockForUpdate()->findOrFail($req->id);
        $payments = $opts['payments'] ?? null;
        $hasRecipientPayments = is_array($payments) && $payments !== [];

        if ($hasRecipientPayments && ! $req->isAddressedToStorekeeper()) {
            return $this->onFulfilledByRecipientPayments($req, $actor, $opts, $payments);
        }

        $scope = (string) ($opts['fulfillment_scope'] ?? $req->fulfillment_scope ?? 'whole');

        if (! in_array($scope, ['whole', 'items'], true)) {
            throw ValidationException::withMessages([
                'fulfillment_scope' => 'Choose whole-request or item-by-item fulfillment.',
            ]);
        }

        if ($req->fulfillment_scope && $req->fulfillment_scope !== $scope) {
            throw ValidationException::withMessages([
                'fulfillment_scope' => 'The fulfillment method cannot be changed after the first installment.',
            ]);
        }

        $targetAmount = bcadd((string) ($req->amended_amount ?? $req->original_amount), '0', 2);
        $fulfilledAmount = bcadd((string) $req->fulfilled_amount, '0', 2);
        $remainingAmount = bcsub($targetAmount, $fulfilledAmount, 2);
        $fulfillmentItems = [];
        $qty = '0';

        if ($scope === 'items' || $req->isAddressedToStorekeeper()) {
            $requestedItems = $scope === 'whole'
                ? $req->items->map(fn (RequisitionItem $item) => [
                    'requisition_item_id' => $item->id,
                    'quantity' => bcsub((string) $item->quantity, (string) $item->fulfilled_quantity, 3),
                ])->all()
                : ($opts['items'] ?? []);

            if (! is_array($requestedItems) || $requestedItems === []) {
                throw ValidationException::withMessages([
                    'items' => 'Enter a quantity for at least one requisition item.',
                ]);
            }

            $amount = '0';
            $seen = [];
            foreach ($requestedItems as $input) {
                $itemId = (int) ($input['requisition_item_id'] ?? 0);
                $item = $req->items->firstWhere('id', $itemId);
                if (! $item || isset($seen[$itemId])) {
                    throw ValidationException::withMessages([
                        'items' => 'Each selected line must be unique and belong to this requisition.',
                    ]);
                }
                $seen[$itemId] = true;

                $itemQty = bcadd((string) ($input['quantity'] ?? '0'), '0', 3);
                $itemRemaining = bcsub(
                    (string) $item->quantity,
                    (string) $item->fulfilled_quantity,
                    3
                );
                if (bccomp($itemQty, '0', 3) !== 1 || bccomp($itemQty, $itemRemaining, 3) === 1) {
                    throw ValidationException::withMessages([
                        'items' => "Quantity for {$item->description} must be greater than zero and no more than {$itemRemaining}.",
                    ]);
                }

                $lineAmount = $item->amountForQuantity($itemQty);
                $fulfillmentItems[] = array_merge($input, [
                    'requisition_item_id' => $item->id,
                    'quantity' => $itemQty,
                    'unit_cost' => (string) $item->unit_cost,
                ]);
                $qty = bcadd($qty, $itemQty, 3);
                $amount = bcadd($amount, $lineAmount, 2);
            }
        } else {
            $amount = bcadd((string) ($opts['amount'] ?? $remainingAmount), '0', 2);
            if (bccomp($amount, '0', 2) !== 1 || bccomp($amount, $remainingAmount, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => "Amount must be greater than zero and no more than {$remainingAmount}.",
                ]);
            }

            $totalQty = $this->sumItemQuantities($req);
            if (bccomp($targetAmount, '0', 2) === 1) {
                $previousConsumedQty = bcmul(
                    $totalQty,
                    bcdiv($fulfilledAmount, $targetAmount, 6),
                    3
                );
                $newCumulativeAmount = bcadd($fulfilledAmount, $amount, 2);
                $newConsumedQty = bccomp($newCumulativeAmount, $targetAmount, 2) === 0
                    ? $totalQty
                    : bcmul(
                        $totalQty,
                        bcdiv($newCumulativeAmount, $targetAmount, 6),
                        3
                    );
                $qty = bcsub($newConsumedQty, $previousConsumedQty, 3);
            }
        }

        if (bccomp($amount, $remainingAmount, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => "This fulfillment exceeds the remaining amount of {$remainingAmount}.",
            ]);
        }

        if ($req->boq_item_id) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
            if (bccomp($qty, '0', 3) === 1) {
                $boqItem->decrement('reserved_qty', $qty);
                $boqItem->increment('consumed_qty', $qty);
            }
        }

        $disbursement = null;
        $fulfillmentOpts = array_merge($opts, ['items' => $fulfillmentItems]);

        if ($req->isAddressedToStorekeeper()) {
            $this->fulfillmentService->fulfillStock($req, $actor, $fulfillmentOpts);
        } else {
            $disbursement = $this->fulfillmentService->fulfillCash($req, $actor, $amount, $opts);
        }

        foreach ($fulfillmentItems as $fulfilledItem) {
            $req->items->firstWhere('id', $fulfilledItem['requisition_item_id'])
                ?->increment('fulfilled_quantity', $fulfilledItem['quantity']);
        }

        $newFulfilledAmount = bcadd($fulfilledAmount, $amount, 2);
        $req->update([
            'fulfillment_scope' => $scope,
            'fulfilled_amount' => $newFulfilledAmount,
        ]);

        $this->recordExpenseFromRequisition(
            $req,
            $actor,
            $amount,
            $disbursement,
            collect($fulfillmentItems)->pluck('requisition_item_id')->all(),
        );

        $this->finalizePayrollFromFulfillment($req, $actor);

        $fullyFulfilled = $scope === 'whole' && ! $req->isAddressedToStorekeeper()
            ? bccomp($newFulfilledAmount, $targetAmount, 2) === 0
            : $req->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->doesntExist();

        if ($fullyFulfilled && $scope === 'whole') {
            $req->items()->update(['fulfilled_quantity' => DB::raw('quantity')]);
        }

        return $fullyFulfilled
            ? RequisitionStatus::Fulfilled->value
            : RequisitionStatus::PartiallyFulfilled->value;
    }

    /**
     * Cash fulfillment split into one disbursement per recipient (maombi lines).
     *
     * @param  array<string, mixed>  $opts
     * @param  list<array<string, mixed>>  $payments
     */
    private function onFulfilledByRecipientPayments(
        Requisition $req,
        User $actor,
        array $opts,
        array $payments,
    ): string {
        $scope = 'items';
        if ($req->fulfillment_scope && $req->fulfillment_scope !== $scope) {
            throw ValidationException::withMessages([
                'fulfillment_scope' => 'The fulfillment method cannot be changed after the first installment.',
            ]);
        }

        $targetAmount = bcadd((string) ($req->amended_amount ?? $req->original_amount), '0', 2);
        $fulfilledAmount = bcadd((string) $req->fulfilled_amount, '0', 2);
        $remainingAmount = bcsub($targetAmount, $fulfilledAmount, 2);

        $totalAmount = '0.00';
        $totalQty = '0';
        $allFulfillmentItems = [];
        $seenItemIds = [];

        foreach ($payments as $index => $payment) {
            if (! is_array($payment)) {
                throw ValidationException::withMessages([
                    'payments' => 'Each payment must include recipient and account details.',
                ]);
            }

            $recipientKey = (string) ($payment['recipient_key'] ?? '');
            $recipientId = isset($payment['recipient_id']) && (int) $payment['recipient_id'] > 0
                ? (int) $payment['recipient_id']
                : null;

            $requestedItems = $payment['items'] ?? null;
            if (! is_array($requestedItems) || $requestedItems === []) {
                $requestedItems = $req->items
                    ->filter(function (RequisitionItem $item) use ($recipientId, $recipientKey) {
                        $itemRemaining = bcsub(
                            (string) $item->quantity,
                            (string) $item->fulfilled_quantity,
                            3
                        );
                        if (bccomp($itemRemaining, '0', 3) !== 1) {
                            return false;
                        }

                        return $this->itemMatchesRecipient($item, $recipientId, $recipientKey);
                    })
                    ->map(fn (RequisitionItem $item) => [
                        'requisition_item_id' => $item->id,
                        'quantity' => bcsub((string) $item->quantity, (string) $item->fulfilled_quantity, 3),
                    ])
                    ->values()
                    ->all();
            }

            if ($requestedItems === []) {
                throw ValidationException::withMessages([
                    "payments.{$index}.items" => 'No remaining request lines for this recipient.',
                ]);
            }

            $paymentAmount = '0.00';
            $paymentItems = [];
            foreach ($requestedItems as $input) {
                $itemId = (int) ($input['requisition_item_id'] ?? 0);
                $item = $req->items->firstWhere('id', $itemId);
                if (! $item || isset($seenItemIds[$itemId])) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.items" => 'Each selected line must be unique and belong to this recipient.',
                    ]);
                }

                if (! $this->itemMatchesRecipient($item, $recipientId, $recipientKey)) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.items" => "Line {$item->description} does not belong to this recipient.",
                    ]);
                }

                $seenItemIds[$itemId] = true;
                $itemQty = bcadd((string) ($input['quantity'] ?? '0'), '0', 3);
                $itemRemaining = bcsub(
                    (string) $item->quantity,
                    (string) $item->fulfilled_quantity,
                    3
                );
                if (bccomp($itemQty, '0', 3) !== 1 || bccomp($itemQty, $itemRemaining, 3) === 1) {
                    throw ValidationException::withMessages([
                        "payments.{$index}.items" => "Quantity for {$item->description} must be greater than zero and no more than {$itemRemaining}.",
                    ]);
                }

                $lineAmount = $item->amountForQuantity($itemQty);
                $paymentItems[] = [
                    'requisition_item_id' => $item->id,
                    'quantity' => $itemQty,
                    'unit_cost' => (string) $item->unit_cost,
                ];
                $paymentAmount = bcadd($paymentAmount, $lineAmount, 2);
                $totalQty = bcadd($totalQty, $itemQty, 3);
            }

            if (bccomp($paymentAmount, '0', 2) !== 1) {
                throw ValidationException::withMessages([
                    "payments.{$index}.items" => 'Payment amount must be greater than zero.',
                ]);
            }

            $payeeName = trim((string) ($payment['payee'] ?? $payment['account_name'] ?? ''));
            if ($payeeName === '') {
                $payeeName = trim((string) ($req->items->firstWhere('id', $paymentItems[0]['requisition_item_id'])?->recipient_name ?? 'Recipient'));
            }

            $disbursement = $this->fulfillmentService->fulfillCash($req, $actor, $paymentAmount, [
                'payee' => $payeeName,
                'account_name' => $payment['account_name'] ?? $payeeName,
                'account_number' => $payment['account_number'] ?? '',
                'method' => $payment['method'] ?? 'cash',
                'payment_date' => $payment['payment_date'] ?? null,
                'reference_no' => $payment['reference_no'] ?? '',
                'recipient_id' => $recipientId,
            ]);

            foreach ($paymentItems as $fulfilledItem) {
                $req->items->firstWhere('id', $fulfilledItem['requisition_item_id'])
                    ?->increment('fulfilled_quantity', $fulfilledItem['quantity']);
                $allFulfillmentItems[] = $fulfilledItem;
            }

            $this->recordExpenseFromRequisition(
                $req,
                $actor,
                $paymentAmount,
                $disbursement,
                collect($paymentItems)->pluck('requisition_item_id')->all(),
            );

            $totalAmount = bcadd($totalAmount, $paymentAmount, 2);
        }

        if (bccomp($totalAmount, $remainingAmount, 2) === 1) {
            throw ValidationException::withMessages([
                'payments' => "These payments exceed the remaining amount of {$remainingAmount}.",
            ]);
        }

        if ($req->boq_item_id && bccomp($totalQty, '0', 3) === 1) {
            $boqItem = BoqItem::lockForUpdate()->findOrFail($req->boq_item_id);
            $boqItem->decrement('reserved_qty', $totalQty);
            $boqItem->increment('consumed_qty', $totalQty);
        }

        $newFulfilledAmount = bcadd($fulfilledAmount, $totalAmount, 2);
        $req->update([
            'fulfillment_scope' => $scope,
            'fulfilled_amount' => $newFulfilledAmount,
        ]);

        $this->finalizePayrollFromFulfillment($req, $actor);

        $fullyFulfilled = $req->items()->whereColumn('fulfilled_quantity', '<', 'quantity')->doesntExist();

        return $fullyFulfilled
            ? RequisitionStatus::Fulfilled->value
            : RequisitionStatus::PartiallyFulfilled->value;
    }

    private function itemMatchesRecipient(
        RequisitionItem $item,
        ?int $recipientId,
        string $recipientKey,
    ): bool {
        if ($recipientId !== null) {
            return (int) $item->recipient_id === $recipientId;
        }

        $expectedName = str_starts_with($recipientKey, 'name:')
            ? substr($recipientKey, 5)
            : $recipientKey;

        return trim((string) $item->recipient_name) === trim($expectedName)
            && ($item->recipient_id === null || (int) $item->recipient_id === 0);
    }

    /**
     * When a Salaries / payroll requisition is paid, mark or create payroll runs
     * so the payroll module and reports show who was paid.
     */
    private function finalizePayrollFromFulfillment(Requisition $req, User $actor): void
    {
        if (! $req->isOrganizationWide()) {
            return;
        }

        app(PayrollService::class)->recordRunsFromFulfilledRequisition($req, $actor);
    }

    /**
     * Fulfilled requisitions become expenses:
     * project-scoped → direct; organization-wide → overhead (indirect).
     */
    private function recordExpenseFromRequisition(
        Requisition $req,
        User $actor,
        string $amount,
        ?CashDisbursement $disbursement = null,
        array $itemIds = [],
    ): Expense {
        $req->loadMissing(['category', 'items', 'payrollRun', 'categories']);

        $category = $req->isOrganizationWide()
            ? ExpenseCategory::Indirect
            : ExpenseCategory::Direct;

        $expenseItems = $itemIds === []
            ? $req->items
            : $req->items->whereIn('id', $itemIds);
        $lineDescriptions = $expenseItems
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

        $categoryLabel = $req->categoryLabel();
        $isPayroll = $req->payrollRun !== null
            || str_contains(mb_strtolower($categoryLabel), mb_strtolower(OrganizationFundUse::SALARIES))
            || str_contains(mb_strtolower($categoryLabel), 'payroll');

        $subType = $isPayroll
            ? OrganizationFundUse::SALARIES
            : ($categoryLabel !== '—'
                ? $categoryLabel
                : ($req->department !== '' ? $req->department : 'Requisition'));

        $activityRef = $req->payrollRun
            ? "payroll_run:{$req->payrollRun->id}"
            : $req->requisition_no;

        $expense = Expense::create([
            'project_id' => $req->project_id,
            'boq_item_id' => $req->boq_item_id,
            'requisition_id' => $req->id,
            'category' => $category,
            'sub_type' => $subType,
            'activity_ref' => $activityRef,
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

        if ($req->project_id) {
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

    /**
     * Uncommitted cash available to approve/amend a finance-addressed requisition.
     *
     * @return array{
     *     spends_cash: bool,
     *     scope: 'organization'|'project',
     *     cash_on_hand: string,
     *     committed: string,
     *     available: string,
     *     required: string,
     *     exceeds: bool,
     * }|null
     */
    public function cashAvailability(Requisition $req, ?string $amount = null): ?array
    {
        if (! $this->spendsCashFloat($req)) {
            return null;
        }

        $required = $amount ?? bcsub(
            (string) ($req->amended_amount ?? $req->original_amount),
            (string) $req->fulfilled_amount,
            2
        );

        // Shared Finance Wallet — any approved finance requisition commits against it.
        $cashOnHand = $this->moneyAccountService->financeBalance();

        $committedQuery = Requisition::query()
            ->where('id', '!=', $req->id)
            ->whereIn('status', [
                RequisitionStatus::Approved,
                RequisitionStatus::Amended,
                RequisitionStatus::PartiallyFulfilled,
            ])
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

        $committedElsewhere = '0';
        foreach ($committedQuery->get() as $other) {
            $committedElsewhere = bcadd(
                $committedElsewhere,
                bcsub(
                    (string) ($other->amended_amount ?? $other->original_amount),
                    (string) $other->fulfilled_amount,
                    2
                ),
                2
            );
        }

        $available = bcsub($cashOnHand, $committedElsewhere, 2);

        return [
            'spends_cash' => true,
            'scope' => 'finance_wallet',
            'cash_on_hand' => $cashOnHand,
            'committed' => $committedElsewhere,
            'available' => $available,
            'required' => $required,
            'exceeds' => bccomp($required, $available, 2) === 1,
        ];
    }

    private function assertCashAvailable(Requisition $req, string $amount, User $actor, array $opts): void
    {
        $availability = $this->cashAvailability($req, $amount);

        if ($availability === null || ! $availability['exceeds']) {
            return;
        }

        if ($this->canBypassLimit($actor, $opts)) {
            return;
        }

        $this->notifyRole('Finance Manager', 'cash_shortfall', [
            'requisition_id' => $req->id,
            'requisition_no' => $req->requisition_no,
            'required' => $availability['required'],
            'available' => $availability['available'],
            'cash_on_hand' => $availability['cash_on_hand'],
            'committed' => $availability['committed'],
            'scope' => $availability['scope'],
        ]);

        throw new InsufficientCashException(
            $availability['required'],
            $availability['available'],
            'Amend the requisition down to available cash, or reject it. Approved requests cannot be amended later.',
        );
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

    /**
     * @param  list<int|string>  $categoryIds
     */
    private function syncCategories(Requisition $requisition, array $categoryIds): void
    {
        $sync = [];
        foreach (array_values($categoryIds) as $index => $categoryId) {
            $id = (int) $categoryId;
            if ($id <= 0) {
                continue;
            }
            $sync[$id] = ['sort_order' => $index];
        }

        $requisition->categories()->sync($sync);

        $primaryId = array_key_first($sync);
        if ($primaryId !== null && (int) $requisition->requisition_category_id !== (int) $primaryId) {
            $requisition->update(['requisition_category_id' => $primaryId]);
        }
    }

    /**
     * @param  list<array{recipient_id?: int|null, name?: string, phone?: string|null, position_id?: int|null, position_name?: string|null}>  $recipients
     */
    private function syncRecipients(Requisition $requisition, array $recipients): void
    {
        $requisition->recipients()->delete();

        foreach (array_values($recipients) as $index => $recipient) {
            $recipientId = isset($recipient['recipient_id']) && $recipient['recipient_id'] !== ''
                ? (int) $recipient['recipient_id']
                : null;
            $name = trim((string) ($recipient['name'] ?? ''));
            $positionId = isset($recipient['position_id']) && $recipient['position_id'] !== ''
                ? (int) $recipient['position_id']
                : null;

            if ($name === '' && ! $positionId && ! $recipientId) {
                continue;
            }

            RequisitionRecipient::create([
                'requisition_id' => $requisition->id,
                'recipient_id' => $recipientId,
                'name' => $name !== '' ? $name : '—',
                'phone' => $recipient['phone'] ?? null,
                'position_id' => $positionId,
                'position_name' => $recipient['position_name'] ?? null,
                'sort_order' => $index,
            ]);

            if ($recipientId && $requisition->project_id) {
                $requisition->project?->recipients()->syncWithoutDetaching([$recipientId]);
            }
        }
    }

    private function generateRequisitionNo(): string
    {
        $year = now()->year;
        $prefix = "REQ-{$year}-";

        // Soft-deleted rows still occupy the unique index, so include them when
        // allocating the next number. Lock to reduce concurrent collisions.
        $last = Requisition::withTrashed()
            ->where('requisition_no', 'like', "{$prefix}%")
            ->orderByDesc('requisition_no')
            ->lockForUpdate()
            ->value('requisition_no');

        $sequence = 1;

        if ($last && preg_match('/REQ-\d{4}-(\d+)$/', $last, $matches)) {
            $sequence = (int) $matches[1] + 1;
        }

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function isRequisitionNoUniqueViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'requisitions_requisition_no_unique')
            || (str_contains($message, 'requisition_no') && (
                str_contains($message, 'Unique violation')
                || str_contains($message, 'UNIQUE constraint failed')
                || (string) $e->getCode() === '23505'
            ));
    }
}
