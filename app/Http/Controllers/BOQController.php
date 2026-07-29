<?php

namespace App\Http\Controllers;

use App\Enums\BoqItemCategory;
use App\Http\Requests\BulkDeleteBoqItemsRequest;
use App\Http\Requests\ImportBoqRequest;
use App\Http\Requests\StoreBoqItemsRequest;
use App\Http\Requests\UpdateBoqItemRequest;
use App\Models\BoqSection;
use App\Models\Project;
use App\Services\BOQService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BOQController extends Controller
{
    public function __construct(private BOQService $boqService) {}

    public function tree(Request $request, int $id): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'read');

        return Inertia::render('BOQ/Index', [
            'project' => $project,
            'sections' => $this->boqService->tree($project),
        ]);
    }

    public function create(Request $request, int $id): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'create');

        $sectionNames = BoqSection::query()
            ->where('project_id', $project->id)
            ->orderBy('display_order')
            ->pluck('name')
            ->all();

        return Inertia::render('BOQ/Create', [
            'project' => $project,
            'sectionNames' => $sectionNames,
            'categories' => collect(BoqItemCategory::cases())->map(fn (BoqItemCategory $category) => [
                'value' => $category->value,
                'label' => ucwords(str_replace('_', ' ', $category->value)),
            ])->all(),
        ]);
    }

    public function store(StoreBoqItemsRequest $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'create');

        $created = $this->boqService->storeItems($project, $request->validated('items'));

        return redirect()
            ->route('projects.boq', $project->id)
            ->with('success', count($created) === 1
                ? 'BOQ item added successfully.'
                : count($created).' BOQ items added successfully.');
    }

    public function importForm(Request $request, int $id): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'import');

        $importResult = session('importResult');

        return Inertia::render('BOQ/Import', [
            'project' => $project,
            'import_result' => is_array($importResult) ? [
                'success_count' => count($importResult['success'] ?? []),
                'error_count' => count($importResult['errors'] ?? []),
                'errors' => collect($importResult['errors'] ?? [])->map(fn ($error) => [
                    'row' => $error['row'] ?? null,
                    'message' => $error['message'] ?? 'Unknown error',
                ])->values()->all(),
            ] : null,
        ]);
    }

    public function import(ImportBoqRequest $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'import');

        $result = $this->boqService->importFromFile($project, $request->file('file'));
        $successCount = count($result['success']);
        $errorCount = count($result['errors']);

        if ($successCount > 0 && $errorCount === 0) {
            return redirect()
                ->route('projects.boq', $project->id)
                ->with('success', $successCount === 1
                    ? '1 BOQ item imported successfully.'
                    : "{$successCount} BOQ items imported successfully.");
        }

        if ($successCount > 0) {
            return redirect()
                ->route('projects.boq.import-form', $project->id)
                ->with('error', "Imported {$successCount} item(s), but {$errorCount} row(s) failed.")
                ->with('importResult', $result);
        }

        return redirect()
            ->route('projects.boq.import-form', $project->id)
            ->with('error', $errorCount > 0
                ? "BOQ import failed. {$errorCount} row(s) could not be imported."
                : 'BOQ import failed. No rows were found in the file.')
            ->with('importResult', $result);
    }

    public function export(Request $request, int $id): BinaryFileResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'read');

        $templateOnly = $request->boolean('template');

        return $this->boqService->exportExcel($project, $templateOnly);
    }

    public function edit(Request $request, int $id, int $itemId): Response
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'update');

        $item = $this->boqService->findItemForProject($project, $itemId);

        $sectionNames = BoqSection::query()
            ->where('project_id', $project->id)
            ->orderBy('display_order')
            ->pluck('name')
            ->all();

        return Inertia::render('BOQ/Edit', [
            'project' => $project,
            'sectionNames' => $sectionNames,
            'categories' => collect(BoqItemCategory::cases())->map(fn (BoqItemCategory $category) => [
                'value' => $category->value,
                'label' => ucwords(str_replace('_', ' ', $category->value)),
            ])->all(),
            'item' => [
                'id' => $item->id,
                'section' => $item->section?->name ?? 'General',
                'description' => $item->description,
                'unit' => $item->unit,
                'category' => $item->category instanceof BoqItemCategory
                    ? $item->category->value
                    : (string) $item->category,
                'budgeted_qty' => (string) $item->budgeted_qty,
                'unit_rate' => (string) $item->unit_rate,
            ],
        ]);
    }

    public function update(UpdateBoqItemRequest $request, int $id, int $itemId): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'update');

        $item = $this->boqService->findItemForProject($project, $itemId);
        $this->boqService->updateItem($project, $item, $request->validated());

        return redirect()
            ->route('projects.boq', $project->id)
            ->with('success', 'BOQ item updated successfully.');
    }

    public function destroy(Request $request, int $id, int $itemId): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'update');

        $deleted = $this->boqService->deleteItems($project, [$itemId]);

        return redirect()
            ->route('projects.boq', $project->id)
            ->with('success', $deleted > 0 ? 'BOQ item deleted.' : 'No BOQ item was deleted.');
    }

    public function bulkDestroy(BulkDeleteBoqItemsRequest $request, int $id): RedirectResponse
    {
        $project = Project::findOrFail($id);
        $this->authorizePermission($request->user(), 'boq', 'update');

        $deleted = $this->boqService->deleteItems($project, $request->validated('ids'));

        return redirect()
            ->route('projects.boq', $project->id)
            ->with('success', $deleted === 1
                ? '1 BOQ item deleted.'
                : "{$deleted} BOQ items deleted.");
    }
}
