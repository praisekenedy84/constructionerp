<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsForAllTenants;
use App\Services\InventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckLowStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsForAllTenants, SerializesModels;

    public function handle(InventoryService $inventoryService): void
    {
        $this->runForAllTenants(fn () => $inventoryService->checkLowStock());
    }
}
