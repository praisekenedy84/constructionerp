<?php

namespace App\Http\Controllers;

use App\Enums\PayrollRunStatus;
use App\Http\Requests\GeneratePayrollRequest;
use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Services\PayrollService;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayrollController extends Controller
{
    public function __construct(private PayrollService $payrollService) {}

    public function index(Request $request, int $projectId): Response
    {
        $this->authorizePermission($request->user(), 'payroll', 'read');

        $project = Project::findOrFail($projectId);

        $employeeListing = ListingQuery::for(
            Employee::query()->where('project_id', $project->id),
            $request,
        )
            ->search(['name', 'employee_no', 'role'])
            ->dateRange('created_at')
            ->sort(['name', 'employee_no', 'role', 'created_at']);

        $runsQuery = PayrollRun::query()
            ->where('project_id', $project->id)
            ->withCount('items')
            ->withSum('items', 'net_pay');

        if ($from = $request->input('from')) {
            $runsQuery->whereDate('period_end', '>=', $from);
        }
        if ($to = $request->input('to')) {
            $runsQuery->whereDate('period_end', '<=', $to);
        }

        return Inertia::render('Payroll/Index', [
            'project' => $project,
            'employees' => $employeeListing->paginate(25),
            'recent_runs' => $runsQuery
                ->orderByDesc('period_end')
                ->paginate(ListingQuery::resolvePerPage($request))
                ->withQueryString(),
            'filters' => $employeeListing->filters(),
        ]);
    }

    public function runs(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'payroll', 'read');

        $query = PayrollRun::query()
            ->with('project:id,code,name')
            ->withCount('items')
            ->withSum('items', 'net_pay');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search(['project.code', 'project.name'])
            ->dateRange('period_end')
            ->sort(['period_end', 'period_start', 'status', 'created_at'], 'period_end');

        return Inertia::render('Payroll/Runs', [
            'runs' => $listing->paginate(25),
            'filters' => $listing->filters([
                'project_id' => $request->input('project_id'),
                'status' => $request->input('status'),
            ]),
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
            'status_options' => [
                ['value' => PayrollRunStatus::Draft->value, 'label' => 'Draft'],
                ['value' => PayrollRunStatus::Approved->value, 'label' => 'Approved'],
                ['value' => PayrollRunStatus::Posted->value, 'label' => 'Posted'],
            ],
        ]);
    }

    public function show(Request $request, int $id): Response
    {
        $this->authorizePermission($request->user(), 'payroll', 'read');

        $run = PayrollRun::query()
            ->with(['project:id,code,name', 'items.employee'])
            ->withCount('items')
            ->withSum('items', 'net_pay')
            ->findOrFail($id);

        return Inertia::render('Payroll/Show', [
            'run' => $run,
            'total_net_pay' => (string) ($run->items_sum_net_pay ?? '0'),
        ]);
    }

    public function generateForm(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'payroll', 'create');

        $projectId = $request->integer('project_id')
            ?: session('current_project_id')
            ?: Project::query()->orderByDesc('created_at')->value('id');

        $project = Project::findOrFail($projectId);
        $periodStart = $request->input('period_start', now()->startOfMonth()->toDateString());
        $periodEnd = $request->input('period_end', now()->endOfMonth()->toDateString());

        $preview = null;

        if ($runId = $request->integer('run_id')) {
            $run = PayrollRun::with('items.employee')->findOrFail($runId);
            $preview = $run->items;
        } elseif ($request->filled('period_start') && $request->filled('period_end')) {
            $previewData = $this->payrollService->generatePreview($project, $periodStart, $periodEnd);
            $preview = collect($previewData['items'])->map(fn ($item) => [
                'id' => $item['employee_id'],
                'payroll_run_id' => 0,
                'employee_id' => $item['employee_id'],
                'base' => $item['base'],
                'overtime' => $item['overtime'],
                'allowances' => $item['allowances'],
                'deductions_total' => $item['deductions_total'],
                'net_pay' => $item['net_pay'],
                'employee' => [
                    'id' => $item['employee_id'],
                    'name' => $item['employee_name'],
                    'employee_no' => $item['employee_no'],
                ],
            ])->values()->all();
        }

        return Inertia::render('Payroll/Generate', [
            'project' => $project,
            'preview' => $preview,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ]);
    }

    public function generate(GeneratePayrollRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'payroll', 'create');

        $run = $this->payrollService->generate($request->validated(), $request->user());

        return redirect()
            ->route('payroll.generate.form', [
                'project_id' => $run->project_id,
                'run_id' => $run->id,
            ])
            ->with('success', "Payroll run #{$run->id} generated for review.");
    }

    public function post(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'payroll', 'approve');

        $run = PayrollRun::findOrFail($id);

        try {
            $this->payrollService->post($run, $request->user());
        } catch (\App\Exceptions\InsufficientCashException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('payroll.runs.show', $run->id)
            ->with('success', 'Payroll posted. Salaries paid from organization cash on hand.');
    }
}
