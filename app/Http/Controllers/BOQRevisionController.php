<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBoqRevisionRequest;
use App\Models\BoqRevision;
use App\Services\BOQService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BOQRevisionController extends Controller
{
    public function __construct(private BOQService $boqService) {}

    public function store(StoreBoqRevisionRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Project Manager']);

        $revision = $this->boqService->createRevision(
            $request->integer('project_id'),
            $request->user(),
            $request->validated('reason'),
        );

        return back()->with('success', "BOQ revision v{$revision->version_no} created.");
    }

    public function activate(Request $request, int $id): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Managing Director']);

        $revision = BoqRevision::findOrFail($id);
        $this->boqService->activateRevision($revision, $request->user());

        return back()->with('success', 'BOQ revision activated.');
    }
}
