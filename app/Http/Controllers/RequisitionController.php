<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddRequisitionAttachmentRequest;
use App\Http\Requests\StoreRequisitionRequest;
use App\Http\Requests\TransitionRequisitionRequest;
use App\Models\ApprovalStep;
use App\Support\ListingQuery;
use App\Models\BoqItem;
use App\Models\InventoryItem;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\StockLocation;
use App\Services\RequisitionService;
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

        $query = Requisition::query()->with(['project', 'requestor', 'boqItem']);

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

        return Inertia::render('Requisitions/Create', [
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
            'boqItems' => BoqItem::orderBy('description')->get(),
        ]);
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

    public function show(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $requisition = Requisition::with([
            'project',
            'boqItem',
            'requestor',
            'items.boqItem',
            'statusHistories.actor',
            'attachments.uploader',
            'approvalSteps',
        ])->findOrFail($id);

        $pendingStep = $requisition->approvalSteps
            ->where('status', 'pending')
            ->sortBy('level')
            ->first();

        return Inertia::render('Requisitions/Show', [
            'requisition' => array_merge($requisition->toArray(), [
                'history' => $requisition->statusHistories,
            ]),
            'pendingStep' => $pendingStep,
            'inventoryItems' => InventoryItem::orderBy('name')->get(['id', 'code', 'name', 'unit']),
            'stockLocations' => StockLocation::where('project_id', $requisition->project_id)
                ->orderBy('name')
                ->get(['id', 'name']),
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
        }

        return back()->with('success', 'Requisition status updated.');
    }

    public function reviewQueue(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'approve');

        $user = $request->user();
        $roles = $user->getRoleNames()->toArray();

        $query = ApprovalStep::query()
            ->with(['requisition.project', 'requisition.requestor', 'requisition.boqItem'])
            ->where('status', 'pending')
            ->when(! $user->isSuperUser(), fn ($q) => $q->whereIn('required_role', $roles));

        $listing = ListingQuery::for($query, $request)
            ->search(['required_role', 'requisition.requisition_no', 'requisition.department', 'requisition.project.name'])
            ->dateRange('assigned_at')
            ->sort(['assigned_at', 'level', 'required_role'], 'assigned_at');

        return Inertia::render('Requisitions/Review', [
            'approvalSteps' => $listing->paginate(25),
            'filters' => $listing->filters(),
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
}
