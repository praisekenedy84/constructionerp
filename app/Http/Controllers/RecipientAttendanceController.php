<?php

namespace App\Http\Controllers;

use App\Exports\RecipientAttendanceExport;
use App\Http\Requests\StoreRecipientAttendanceRequest;
use App\Models\Project;
use App\Models\Recipient;
use App\Models\RecipientAttendance;
use App\Support\ListingQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipientAttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = RecipientAttendance::query()
            ->with([
                'recipient:id,name,phone,status',
                'project:id,code,name',
            ]);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('recipient_id')) {
            $query->where('recipient_id', $request->integer('recipient_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $listing = ListingQuery::for($query, $request)
            ->search([
                'recipient.name',
                'recipient.phone',
                'project.code',
                'project.name',
                'notes',
            ])
            ->dateRange('date')
            ->sort(['date', 'created_at', 'status'], 'date');

        $totals = (clone $query)
            ->toBase()
            ->reorder()
            ->selectRaw("coalesce(sum(case when status = 'present' then 1 else 0 end), 0) as days_present")
            ->selectRaw("coalesce(sum(case when status = 'absent' then 1 else 0 end), 0) as days_absent")
            ->selectRaw('count(*) as total_records')
            ->first();

        return Inertia::render('Recipients/Attendance', [
            'attendances' => $listing->paginate(25),
            'filters' => $listing->filters([
                'project_id' => $request->input('project_id'),
                'recipient_id' => $request->input('recipient_id'),
                'status' => $request->input('status'),
            ]),
            'summary' => [
                'days_present' => (int) ($totals->days_present ?? 0),
                'days_absent' => (int) ($totals->days_absent ?? 0),
                'total_records' => (int) ($totals->total_records ?? 0),
            ],
            'filterOptions' => [
                'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
                'recipients' => Recipient::query()->orderBy('name')->get(['id', 'name', 'phone', 'status']),
            ],
        ]);
    }

    public function store(StoreRecipientAttendanceRequest $request): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'create');

        $data = $request->validated();

        RecipientAttendance::updateOrCreate(
            [
                'recipient_id' => $data['recipient_id'],
                'project_id' => $data['project_id'],
                'date' => $data['date'],
            ],
            [
                'check_in' => $data['status'] === 'absent' ? null : ($data['check_in'] ?? null),
                'check_out' => $data['status'] === 'absent' ? null : ($data['check_out'] ?? null),
                'status' => $data['status'],
                'notes' => $data['notes'] ?? null,
            ],
        );

        return back()->with('success', 'Recipient attendance saved.');
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'update');

        RecipientAttendance::findOrFail($id)->delete();

        return back()->with('success', 'Attendance record deleted.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $query = RecipientAttendance::query()
            ->with([
                'recipient:id,name,phone',
                'project:id,code,name',
            ]);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('recipient_id')) {
            $query->where('recipient_id', $request->integer('recipient_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        ListingQuery::for($query, $request)
            ->search([
                'recipient.name',
                'recipient.phone',
                'project.code',
                'project.name',
                'notes',
            ])
            ->dateRange('date')
            ->sort(['date', 'created_at', 'status'], 'date');

        $rows = $query->get()->map(fn (RecipientAttendance $row) => [
            $row->recipient?->name,
            $row->project?->code,
            $row->project?->name,
            optional($row->date)?->toDateString(),
            $row->check_in,
            $row->check_out,
            $row->status,
            $row->notes,
        ]);

        return Excel::download(
            new RecipientAttendanceExport($rows),
            'recipient-attendance-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }
}
