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
        $dashboard = $this->reportService->dashboardOverview();

        return Inertia::render('Dashboard', [
            'stats' => $dashboard['stats'],
            'charts' => $dashboard['charts'],
        ]);
    }
}
