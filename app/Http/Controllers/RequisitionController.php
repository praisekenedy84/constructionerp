<?php

namespace App\Http\Controllers;

use App\Enums\InventoryItemCategory;
use App\Enums\RequisitionStatus;
use App\Exceptions\BOQLimitExceededException;
use App\Exceptions\InvalidTransitionException;
use App\Http\Requests\AddRequisitionAttachmentRequest;
use App\Http\Requests\StoreRequisitionRequest;
use App\Http\Requests\TransitionRequisitionRequest;
use App\Models\ApprovalStep;
use App\Models\BoqItem;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InventoryItem;
use App\Models\Position;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use App\Models\StockLocation;
use App\Services\ReportService;
use App\Services\RequisitionRegisterService;
use App\Services\RequisitionService;
use App\Support\ListingQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RequisitionController extends Controller
{
    public function __construct(
        private RequisitionService $requisitionService,
        private RequisitionRegisterService $registerService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $filters = [
            'status' => $request->input('status'),
            'department' => $request->input('department'),
            'project_id' => $request->input('project_id'),
            'category_id' => $request->input('category_id'),
            'requestor_id' => $request->input('requestor_id'),
            'search' => $request->input('search'),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'sort' => $request->input('sort'),
            'direction' => $request->input('direction'),
        ];

        return Inertia::render('Requisitions/Index', [
            'rows' => $this->registerService->paginate($request->user(), $request),
            'summary' => $this->registerService->summary($request->user(), $request),
            'filterOptions' => $this->registerService->filterOptions(),
            'filters' => array_filter($filters, fn ($value) => $value !== null && $value !== ''),
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        return $this->registerService->exportExcel($request->user(), $request);
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

        $requisition = Requisition::with(['items.inventoryItem', 'items.category', 'recipients', 'categories'])->findOrFail($id);

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
            ...$this->formOptions($requisition),
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
            'category',
            'categories',
            'recipients.position',
            'requestor',
            'items.boqItem',
            'items.inventoryItem',
            'items.category',
            'items.position',
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
            'stockLocations' => StockLocation::query()
                ->when(
                    $requisition->project_id,
                    fn ($q) => $q->where('project_id', $requisition->project_id),
                )
                ->orderBy('name')
                ->get(['id', 'name']),
            'inventoryCategories' => collect(InventoryItemCategory::cases())->map(fn ($c) => [
                'value' => $c->value,
                'label' => str_replace('_', ' ', ucfirst($c->value)),
            ])->values()->all(),
            'cashOnHand' => app(ReportService::class)
                ->cashPosition(
                    $requisition->project_id
                        ? ['project_id' => $requisition->project_id]
                        : ['scope' => 'organization']
                )['cash_on_hand'],
            'cashAvailability' => $this->requisitionService->cashAvailability($requisition),
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
        } catch (InvalidTransitionException $e) {
            return back()->withErrors(['to_status' => $e->getMessage()]);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        } catch (BOQLimitExceededException|\App\Exceptions\InsufficientCashException|\App\Exceptions\InsufficientStockException|\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            throw $e;
        }

        return back()->with('success', 'Requisition status updated.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $requisition = Requisition::findOrFail($id);
        $user = $request->user();
        $status = $requisition->status instanceof RequisitionStatus
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
                'requisition.items.category',
                'requisition.recipients',
                'requisition.categories',
            ])
            ->where('status', 'pending')
            ->whereHas('requisition', fn ($q) => $q->where('status', 'under_review'));

        $listing = ListingQuery::for($query, $request)
            ->search(['required_role', 'requisition.requisition_no', 'requisition.department', 'requisition.project.name', 'requisition.project.code'])
            ->dateRange('assigned_at')
            ->sort(['assigned_at', 'level', 'required_role'], 'assigned_at');

        $approvalSteps = $listing->paginate(25);

        $cashByRequisitionId = [];
        foreach ($approvalSteps->getCollection() as $step) {
            $requisition = $step->requisition;
            if (! $requisition || isset($cashByRequisitionId[$requisition->id])) {
                continue;
            }

            $availability = $this->requisitionService->cashAvailability($requisition);
            if ($availability !== null) {
                $cashByRequisitionId[$requisition->id] = $availability;
            }
        }

        return Inertia::render('Requisitions/Review', [
            'approvalSteps' => $approvalSteps,
            'cashByRequisitionId' => $cashByRequisitionId,
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
            ->whereIn('status', ['approved', 'amended', 'partially_fulfilled'])
            ->with(['project', 'requestor', 'category', 'categories', 'recipients', 'boqItem', 'items']);

        if ($request->filled('fulfillment_type')) {
            $query->where('fulfillment_type', $request->string('fulfillment_type'));
        }

        if ($request->filled('requisition_id')) {
            $query->where('id', $request->integer('requisition_id'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['requisition_no', 'department', 'project.name'])
            ->dateRange('updated_at')
            ->sort(['requisition_no', 'updated_at', 'original_amount', 'fulfillment_type']);

        return Inertia::render('Requisitions/FulfillQueue', [
            'requisitions' => $listing->paginate(25),
            'filters' => $listing->filters([
                'fulfillment_type' => $request->input('fulfillment_type'),
                'requisition_id' => $request->input('requisition_id'),
            ]),
            'focusRequisitionId' => $request->integer('requisition_id') ?: null,
        ]);
    }

    public function fulfilledList(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = Requisition::query()
            ->visibleTo($request->user())
            ->whereIn('status', ['fulfilled', 'closed'])
            ->with(['project', 'requestor', 'category', 'categories', 'recipients', 'boqItem', 'items']);

        if ($request->filled('fulfillment_type')) {
            $query->where('fulfillment_type', $request->string('fulfillment_type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['requisition_no', 'department', 'project.name', 'requestor.name'])
            ->dateRange('updated_at')
            ->sort(['requisition_no', 'updated_at', 'original_amount', 'fulfillment_type', 'status']);

        return Inertia::render('Requisitions/FulfilledList', [
            'requisitions' => $listing->paginate(25),
            'filters' => $listing->filters([
                'fulfillment_type' => $request->input('fulfillment_type'),
                'status' => $request->input('status'),
            ]),
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
    private function formOptions(?Requisition $requisition = null): array
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

        // Ensure Salaries exists so administrative payroll requisitions can use it.
        app(\App\Services\PayrollService::class)->ensureSalariesCategory();
        app(\App\Services\PayrollService::class)->ensurePayrollDepartment();

        $categories = RequisitionCategory::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'description', 'is_active']);

        $selectedCategoryIds = collect();
        if ($requisition) {
            $selectedCategoryIds = $requisition->relationLoaded('categories')
                ? $requisition->categories->pluck('id')
                : $requisition->categories()->pluck('requisition_categories.id');

            if ($selectedCategoryIds->isEmpty() && $requisition->requisition_category_id) {
                $selectedCategoryIds = collect([$requisition->requisition_category_id]);
            }
        }

        foreach ($selectedCategoryIds as $categoryId) {
            if ($categories->contains('id', $categoryId)) {
                continue;
            }

            $current = RequisitionCategory::query()
                ->whereKey($categoryId)
                ->first(['id', 'name', 'description', 'is_active']);

            if ($current) {
                $categories = $categories->prepend($current)->values();
            }
        }

        $departments = Department::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'description', 'is_active']);

        if ($requisition?->department_id
            && ! $departments->contains('id', $requisition->department_id)) {
            $currentDepartment = Department::query()
                ->whereKey($requisition->department_id)
                ->first(['id', 'name', 'description', 'is_active']);

            if ($currentDepartment) {
                $departments = $departments->prepend($currentDepartment)->values();
            }
        }

        $positions = Position::query()
            ->active()
            ->ordered()
            ->get(['id', 'name', 'description', 'is_active']);

        $selectedPositionIds = collect();
        if ($requisition) {
            $selectedPositionIds = $requisition->relationLoaded('recipients')
                ? $requisition->recipients->pluck('position_id')->filter()
                : $requisition->recipients()->pluck('position_id')->filter();

            if ($selectedPositionIds->isEmpty() && $requisition->position_id) {
                $selectedPositionIds = collect([$requisition->position_id]);
            }
        }

        foreach ($selectedPositionIds as $positionId) {
            if ($positions->contains('id', $positionId)) {
                continue;
            }

            $currentPosition = Position::query()
                ->whereKey($positionId)
                ->first(['id', 'name', 'description', 'is_active']);

            if ($currentPosition) {
                $positions = $positions->prepend($currentPosition)->values();
            }
        }

        return [
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'boqItems' => $boqItems,
            'inventoryItems' => InventoryItem::orderBy('name')->get(['id', 'code', 'name', 'unit', 'category']),
            'categories' => $categories,
            'departments' => $departments,
            'positions' => $positions,
            'employees' => Employee::query()
                ->with('project:id,code,name')
                ->orderBy('name')
                ->get([
                    'id',
                    'employee_no',
                    'name',
                    'role',
                    'pay_structure',
                    'daily_rate',
                    'monthly_salary',
                    'project_id',
                ]),
        ];
    }
}
