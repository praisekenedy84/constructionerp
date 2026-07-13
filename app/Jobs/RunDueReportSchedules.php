<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForAllTenants;
use App\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDueReportSchedules implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForAllTenants, SerializesModels;

    public function handle(ReportService $reportService): void
    {
        $this->runForAllTenants(fn () => $reportService->runDueSchedules());
    }
}
