<?php

namespace App\Http\Controllers;

use App\Enums\InventoryItemCategory;
use App\Enums\RequisitionResourceType;
use App\Http\Requests\AddRequisitionAttachmentRequest;
use App\Http\Requests\StoreRequisitionRequest;
use App\Http\Requests\TransitionRequisitionRequest;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\StockLocation;
use App\Services\RequisitionService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RequisitionController extends Controller
{
    public function __construct(private RequisitionService $requisitionService) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = Requisition::query()
            ->visibleTo($request->user())
            ->with([
                'project',
                'requestor',
                'boqItem',
                'approvalSteps' => fn ($q) => $q->where('status', 'pending')->orderBy('level'),
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('department')) {
            $query->where('department', 'like', '%'.$request->string('department').'%');
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['requisition_no', 'department', 'project.name'])
            ->dateRange('created_at')
            ->sort(['requisition_no', 'department', 'status', 'created_at', 'original_amount']);

        return Inertia::render('Requisitions/Index', [
            'requisitions' => $listing->paginate(25),
            'filters' => $listing->filters([
                'status' => $request->input('status'),
                'department' => $request->input('department'),
                'project_id' => $request->input('project_id'),
            ]),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        return Inertia::render('Requisitions/Create', $this->formOptions());
    }

    public function store(StoreRequisitionRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        $data = $request->validated();
        $data['requestor_id'] = $request->user()->id;

        $requisition = $this->requisitionService->create($data);

        return redirect()
            ->route('requisitions.show', $requisition->id)
            ->with('success', 'Requisition created.');
    }

    public function edit(Request $request, int $id): Response|RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $requisition = Requisition::with(['items.inventoryItem'])->findOrFail($id);

        if (! $requisition->isVisibleTo($request->user())) {
            abort(403, 'This draft requisition is only visible to its author until published.');
        }

        if (! $this->requisitionService->isEditable($requisition)) {
            return redirect()
                ->route('requisitions.show', $requisition->id)
                ->withErrors(['status' => 'Only draft or rejected requisitions can be edited.']);
        }

        if (! $request->user()->isSuperUser() && ! $requisition->isOwnedBy($request->user())) {
            abort(403, 'Only the author can edit this requisition.');
        }

        return Inertia::render('Requisitions/Create', [
            ...$this->formOptions(),
            'requisition' => $requisition,
        ]);
    }

    public function update(StoreRequisitionRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $requisition = Requisition::findOrFail($id);

        if (! $requisition->isVisibleTo($request->user())) {
            abort(403, 'This draft requisition is only visible to its author until published.');
        }

        if (! $request->user()->isSuperUser() && ! $requisition->isOwnedBy($request->user())) {
            abort(403, 'Only the author can edit this requisition.');
        }

        try {
            $requisition = $this->requisitionService->update($requisition, $request->validated());
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('requisitions.show', $id)
                ->withErrors(['status' => $e->getMessage()]);
        }

        return redirect()
            ->route('requisitions.show', $requisition->id)
            ->with('success', 'Requisition updated.');
    }

    public function show(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $requisition = Requisition::with([
            'project',
            'boqItem',
            'requestor',
            'items.boqItem',
            'items.inventoryItem',
            'statusHistories.actor',
            'attachments.uploader',
            'approvalSteps',
            'cashDisbursements',
        ])->findOrFail($id);

        if (! $requisition->isVisibleTo($request->user())) {
            abort(403, 'This draft requisition is only visible to its author until published.');
        }

        $pendingStep = $requisition->approvalSteps
            ->where('status', 'pending')
            ->sortBy('level')
            ->first();

        $user = $request->user();
        $canEdit = $this->requisitionService->isEditable($requisition)
            && ($user->isSuperUser() || $requisition->isOwnedBy($user));

        $canDecide = $pendingStep
            && $user->hasModulePermission('requisitions', 'approve')
            && ((int) $requisition->requestor_id !== (int) $user->id || $user->isPlatformAdmin());

        return Inertia::render('Requisitions/Show', [
            'requisition' => array_merge($requisition->toArray(), [
                'history' => $requisition->statusHistories,
            ]),
            'pendingStep' => $pendingStep,
            'canEdit' => $canEdit,
            'canDecide' => $canDecide,
            'inventoryItems' => InventoryItem::orderBy('name')->get(['id', 'code', 'name', 'unit']),
            'stockLocations' => StockLocation::where('project_id', $requisition->project_id)
                ->orderBy('name')
                ->get(['id', 'name']),
            'inventoryCategories' => collect(InventoryItemCategory::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => str_replace('_', ' ', ucfirst($c->value)),
            ])->values()->all(),
            'cashOnHand' => app(\App\Services\ReportService::class)
                ->cashPosition(['project_id' => $requisition->project_id])['cash_on_hand'],
        ]);
    }

    public function transition(TransitionRequisitionRequest $request, int $id): RedirectResponse
    {
        $requisition = Requisition::findOrFail($id);

        try {
            $this->requisitionService->transition(
                $requisition,
                $request->validated('to_status'),
                $request->user(),
                $request->safe()->except('to_status'),
            );
        } catch (\App\Exceptions\InvalidTransitionException $e) {
            return back()->withErrors(['to_status' => $e->getMessage()]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\App\Exceptions\BOQLimitExceededException|\App\Exceptions\InsufficientCashException|\App\Exceptions\InsufficientStockException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Requisition status updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $requisition = Requisition::findOrFail($id);
        $user = $request->user();
        $status = $requisition->status instanceof \App\Enums\RequisitionStatus
            ? $requisition->status->value
            : (string) $requisition->status;

        $needsCancel = ! in_array($status, ['draft', 'rejected', 'cancelled'], true);
        $permission = $needsCancel ? 'cancel' : 'update';

        $this->authorizePermission($user, 'requisitions', $permission);

        if ($status === 'draft' && ! $user->isSuperUser() && ! $requisition->isOwnedBy($user)) {
            abort(403, 'Only the author can delete this draft.');
        }

        try {
            $this->requisitionService->destroy($requisition, $user);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('requisitions.index')
            ->with('success', 'Requisition deleted.');
    }

    public function reviewQueue(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'approve');

        $query = ApprovalStep::query()
            ->with([
                'requisition.project',
                'requisition.requestor',
                'requisition.boqItem',
                'requisition.items',
            ])
            ->where('status', 'pending')
            ->whereHas('requisition', fn ($q) => $q->where('status', 'under_review'));

        $listing = ListingQuery::for($query, $request)
            ->search(['required_role', 'requisition.requisition_no', 'requisition.department', 'requisition.project.name'])
            ->dateRange('assigned_at')
            ->sort(['assigned_at', 'level', 'required_role'], 'assigned_at');

        return Inertia::render('Requisitions/Review', [
            'approvalSteps' => $listing->paginate(25),
            'filters' => $listing->filters([
                'requisition_id' => $request->input('requisition_id'),
            ]),
            'focusRequisitionId' => $request->integer('requisition_id') ?: null,
        ]);
    }

    public function fulfillQueue(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'fulfill');

        $query = Requisition::query()
            ->whereIn('status', ['approved', 'amended'])
            ->with(['project', 'requestor', 'boqItem']);

        if ($request->filled('fulfillment_type')) {
            $query->where('fulfillment_type', $request->string('fulfillment_type'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['requisition_no', 'department', 'project.name'])
            ->dateRange('updated_at')
            ->sort(['requisition_no', 'updated_at', 'original_amount', 'fulfillment_type']);

        return Inertia::render('Requisitions/FulfillQueue', [
            'requisitions' => $listing->paginate(25),
            'filters' => $listing->filters(['fulfillment_type' => $request->input('fulfillment_type')]),
        ]);
    }

    public function addAttachment(AddRequisitionAttachmentRequest $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        $requisition = Requisition::findOrFail($id);

        $this->requisitionService->addAttachment(
            $requisition,
            $request->file('file'),
            $request->validated('document_type'),
            $request->user(),
        );

        return back()->with('success', 'Attachment uploaded.');
    }

    /** @return array<string, mixed> */
    private function formOptions(): array
    {
        $boqItems = BoqItem::query()
            ->with('section:id,project_id')
            ->orderBy('description')
            ->get()
            ->map(fn (BoqItem $item) => [
                'id' => $item->id,
                'description' => $item->description,
                'unit' => $item->unit,
                'unit_rate' => $item->unit_rate,
                'available_qty' => $item->available_qty,
                'project_id' => $item->section?->project_id,
            ]);

        return [
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'boqItems' => $boqItems,
            'inventoryItems' => InventoryItem::orderBy('name')->get(['id', 'code', 'name', 'unit', 'category']),
            'resourceTypes' => collect(RequisitionResourceType::cases())
                ->map(fn ($type) => ['value' => $type->value, 'label' => $type->label()])
                ->values(),
        ];
    }
}
