<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\PermissionService;
use Illuminate\Console\Command;

class SyncTenantPermissionsCommand extends Command
{
    protected $signature = 'tenant:sync-permissions {tenant? : Tenant slug or ID}';

    protected $description = 'Sync module permissions for a tenant';

    public function handle(PermissionService $permissionService): int
    {
        $identifier = $this->argument('tenant') ?? 'demo';
        $tenant = Tenant::where('slug', $identifier)->orWhere('id', $identifier)->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        $tenant->run(fn () => $permissionService->syncTenantPermissions());

        $this->info("Permissions synced for tenant: {$tenant->slug}");

        return self::SUCCESS;
    }
}
