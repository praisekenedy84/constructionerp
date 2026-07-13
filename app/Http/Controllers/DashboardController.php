<?php

namespace App\Http\Controllers;

use App\Services\ReportService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private ReportService $reportService) {}

    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $this->reportService->dashboardStats(),
            'charts' => $this->reportService->dashboardCharts(),
        ]);
    }
}
