<?php

namespace App\Console\Commands;

use App\Services\TenantManagementService;
use Illuminate\Console\Command;

class ProvisionTenantCommand extends Command
{
    protected $signature = 'tenant:provision
                            {name : The tenant company name}
                            {slug : URL-safe slug}
                            {--admin-name=Admin : First admin user name}
                            {--admin-email= : Admin email address}
                            {--admin-password=password : Admin password}';

    protected $description = 'Create a tenant with schema, seed defaults, and admin user';

    public function handle(TenantManagementService $tenantManagement): int
    {
        $email = $this->option('admin-email') ?: "admin@{$this->argument('slug')}.local";

        $tenant = $tenantManagement->provision([
            'name' => $this->argument('name'),
            'slug' => $this->argument('slug'),
            'admin_name' => $this->option('admin-name'),
            'admin_email' => $email,
            'admin_password' => $this->option('admin-password'),
        ]);

        $this->info("Tenant [{$tenant->name}] created (ID: {$tenant->id}).");
        $this->info("Admin user created: {$email}");

        return self::SUCCESS;
    }
}
