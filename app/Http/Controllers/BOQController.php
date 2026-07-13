<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportBoqRequest;
use App\Models\Project;
use App\Services\BOQService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BOQController extends Controller
{
    public function __construct(private BOQService $boqService) {}

    public function tree(Request $request, int $id): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Project Manager', 'Finance Manager']);

        return Inertia::render('BOQ/Index', [
            'project' => $project,
            'sections' => $this->boqService->tree($project),
        ]);
    }

    public function importForm(Request $request, int $id): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Project Manager']);

        return Inertia::render('BOQ/Import', [
            'project' => $project,
            'errors_report' => session('importResult.errors'),
        ]);
    }

    public function import(ImportBoqRequest $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizeRoles($request->user(), ['Quantity Surveyor', 'Project Manager']);

        $result = $this->boqService->importFromFile($project, $request->file('file'));

        return back()->with(
            $result['errors'] ? 'error' : 'success',
            $result['errors']
                ? 'BOQ import completed with errors.'
                : 'BOQ imported successfully.',
        )->with('importResult', $result);
    }
}
