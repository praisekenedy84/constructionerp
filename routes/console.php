<?php

use App\Jobs\CheckLowStock;
use App\Jobs\EscalateStaleApprovals;
use App\Jobs\RunDueReportSchedules;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new EscalateStaleApprovals)->hourly();
Schedule::job(new CheckLowStock)->daily();
Schedule::job(new RunDueReportSchedules)->hourly();
