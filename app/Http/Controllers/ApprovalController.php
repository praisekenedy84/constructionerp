<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveApprovalRequest;
use App\Models\ApprovalStep;
use App\Services\ApprovalService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvalService) {}

    public function steps(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'approve');

        $query = ApprovalStep::query()
            ->with(['requisition.project', 'requisition.requestor'])
            ->where('status', 'pending');

        $listing = ListingQuery::for($query, $request)
            ->search(['required_role', 'requisition.requisition_no', 'requisition.department', 'requisition.project.name'])
            ->dateRange('assigned_at')
            ->sort(['assigned_at', 'level', 'required_role'], 'assigned_at');

        return Inertia::render('Requisitions/Review', [
            'approvalSteps' => $listing->paginate(25),
            'filters' => $listing->filters(),
        ]);
    }

    public function resolve(ResolveApprovalRequest $request, int $id): RedirectResponse
    {
        $step = ApprovalStep::findOrFail($id);

        try {
            $this->approvalService->resolve(
                $step,
                $request->user(),
                $request->validated('action'),
                $request->validated(),
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return redirect()
                ->route('requisitions.review-queue')
                ->with('error', $e->getMessage());
        } catch (\App\Exceptions\BOQLimitExceededException|\App\Exceptions\InsufficientCashException|\InvalidArgumentException $e) {
            return redirect()
                ->route('requisitions.review-queue')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('requisitions.review-queue')
            ->with('success', 'Approval step resolved.');
    }
}
