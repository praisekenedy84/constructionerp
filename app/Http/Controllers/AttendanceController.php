<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceRequest;
use App\Services\PayrollService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function __construct(private PayrollService $payrollService) {}

    public function store(StoreAttendanceRequest $request): RedirectResponse
    {
        $this->authorizeRoles($request->user(), ['HR Officer', 'Site Engineer']);

        $validated = $request->validated();
        $date = $validated['date'] ?? now()->toDateString();
        $entries = array_map(
            fn (array $entry) => [...$entry, 'date' => $entry['date'] ?? $date],
            $validated['entries'],
        );

        $this->payrollService->recordAttendance($entries, $request->user());

        return back()->with('success', 'Attendance recorded.');
    }

    public function index(Request $request): Response
    {
        $this->authorizeRoles($request->user(), ['HR Officer']);

        $grid = $this->payrollService->attendanceGrid($request->all());

        return Inertia::render('Payroll/Attendance', [
            'employees' => $grid['employees'],
            'attendances' => $grid['attendances'],
            'date' => $grid['date'],
        ]);
    }
}
