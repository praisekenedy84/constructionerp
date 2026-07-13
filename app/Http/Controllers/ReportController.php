<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateReportScheduleRequest;
use App\Models\Project;
use App\Services\ReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function index(): Response
    {
        return Inertia::render('Reports/Index', [
            'reports' => $this->reportService->catalog(),
        ]);
    }

    public function preview(Request $request, string $slug): Response
    {
        $preview = $this->reportService->preview($slug, $request->all());

        return Inertia::render('Reports/Show', [
            'report' => [
                'slug' => $preview['slug'],
                'name' => $preview['name'],
                'description' => $preview['description'],
            ],
            'data' => $preview['data'],
            'columns' => $preview['columns'],
            'filters' => $request->all(),
            'projects' => Project::orderBy('name')->get(['id', 'code', 'name']),
        ]);
    }

    public function export(Request $request, string $slug): StreamedResponse
    {
        $format = $request->string('format', 'csv')->toString();

        return $this->reportService->export($slug, $request->all(), $format);
    }

    public function schedules(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['Finance Manager', 'Managing Director', 'Auditor']);

        return Inertia::render('Reports/Schedules', [
            'schedules' => $this->reportService->schedules(),
            'reports' => $this->reportService->catalog(),
        ]);
    }

    public function createSchedule(CreateReportScheduleRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['Finance Manager', 'Managing Director']);

        $this->reportService->createSchedule($request->validated(), $request->user());

        return back()->with('success', 'Report schedule created.');
    }
}
