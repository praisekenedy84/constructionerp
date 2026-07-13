<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForAllTenants;
use App\Services\ApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EscalateStaleApprovals implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForAllTenants, SerializesModels;

    public function handle(ApprovalService $approvalService): void
    {
        $this->runForAllTenants(fn () => $approvalService->escalateStaleApprovals());
    }
}
