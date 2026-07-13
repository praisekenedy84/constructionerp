<?php

namespace App\Jobs\Concerns;

use App\Models\Tenant;

trait RunsForAllTenants
{
    protected function runForAllTenants(callable $callback): void
    {
        foreach (Tenant::all() as $tenant) {
            $tenant->run($callback);
        }
    }
}
