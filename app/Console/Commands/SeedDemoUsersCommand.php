<?php

namespace App\Console\Commands;

use App\Models\CentralUser;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedDemoUsersCommand extends Command
{
    protected $signature = 'tenant:seed-users {tenant? : Tenant slug or ID}';

    protected $description = 'Seed role-based demo users for a tenant';

    /** @var array<int, array{email: string, name: string, role: string}> */
    private array $accounts = [
        ['email' => 'pm@demo.local', 'name' => 'Demo Project Manager', 'role' => 'Project Manager'],
        ['email' => 'engineer@demo.local', 'name' => 'Demo Site Engineer', 'role' => 'Site Engineer'],
        ['email' => 'finance@demo.local', 'name' => 'Demo Finance Manager', 'role' => 'Finance Manager'],
        ['email' => 'manager@demo.local', 'name' => 'Demo Manager', 'role' => 'Manager'],
        ['email' => 'accountant@demo.local', 'name' => 'Demo Accountant', 'role' => 'Accountant'],
        ['email' => 'storekeeper@demo.local', 'name' => 'Demo Storekeeper', 'role' => 'Storekeeper'],
        ['email' => 'procurement@demo.local', 'name' => 'Demo Procurement Officer', 'role' => 'Procurement Officer'],
        ['email' => 'hr@demo.local', 'name' => 'Demo HR Officer', 'role' => 'HR Officer'],
        ['email' => 'qs@demo.local', 'name' => 'Demo Quantity Surveyor', 'role' => 'Quantity Surveyor'],
        ['email' => 'auditor@demo.local', 'name' => 'Demo Auditor', 'role' => 'Auditor'],
    ];

    public function handle(PermissionService $permissionService): int
    {
        $identifier = $this->argument('tenant') ?? 'demo';
        $tenant = Tenant::where('slug', $identifier)->orWhere('id', $identifier)->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        $tenant->run(function () use ($tenant, $permissionService) {
            $permissionService->syncTenantPermissions();

            foreach ($this->accounts as $account) {
                $user = User::firstOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => Hash::make('password'),
                    ],
                );

                $user->syncRoles([$account['role']]);

                tenancy()->central(function () use ($tenant, $account) {
                    CentralUser::firstOrCreate(
                        ['email' => $account['email']],
                        ['tenant_id' => $tenant->id],
                    );
                });

                $this->line("  {$account['email']} ({$account['role']})");
            }
        });

        $this->info('Demo users seeded. Password for all: password');

        return self::SUCCESS;
    }
}
