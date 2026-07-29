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

    protected $description = 'Seed multiple people per role so you can experience each persona';

    /**
     * Multiple accounts per operational role for demo / walkthroughs.
     *
     * @var array<int, array{email: string, name: string, role: string}>
     */
    private array $accounts = [
        // Project Managers (approval level 1)
        ['email' => 'pm@demo.local', 'name' => 'Asha Mwinyi (PM)', 'role' => 'Project Manager'],
        ['email' => 'pm2@demo.local', 'name' => 'James Okello (PM)', 'role' => 'Project Manager'],
        ['email' => 'pm3@demo.local', 'name' => 'Grace Kimaro (PM)', 'role' => 'Project Manager'],

        // Site Engineers (authors of requisitions / drafts)
        ['email' => 'engineer@demo.local', 'name' => 'Joseph Mushi (Engineer)', 'role' => 'Site Engineer'],
        ['email' => 'engineer2@demo.local', 'name' => 'Neema Lyimo (Engineer)', 'role' => 'Site Engineer'],
        ['email' => 'engineer3@demo.local', 'name' => 'Daniel Ngowi (Engineer)', 'role' => 'Site Engineer'],

        // Finance Managers (approval level 2)
        ['email' => 'finance@demo.local', 'name' => 'Fatuma Said (Finance)', 'role' => 'Finance Manager'],
        ['email' => 'finance2@demo.local', 'name' => 'Peter Massawe (Finance)', 'role' => 'Finance Manager'],

        // Managing Director (approval level 3)
        ['email' => 'md@demo.local', 'name' => 'Samuel Mwamba (MD)', 'role' => 'Managing Director'],
        ['email' => 'md2@demo.local', 'name' => 'Halima Juma (MD)', 'role' => 'Managing Director'],

        // Managers
        ['email' => 'manager@demo.local', 'name' => 'David Mollel (Manager)', 'role' => 'Manager'],
        ['email' => 'manager2@demo.local', 'name' => 'Rehema Ally (Manager)', 'role' => 'Manager'],

        // Accountants
        ['email' => 'accountant@demo.local', 'name' => 'Mary Shao (Accountant)', 'role' => 'Accountant'],
        ['email' => 'accountant2@demo.local', 'name' => 'Ibrahim Kileo (Accountant)', 'role' => 'Accountant'],

        // Storekeepers
        ['email' => 'storekeeper@demo.local', 'name' => 'John Kisanga (Store)', 'role' => 'Storekeeper'],
        ['email' => 'storekeeper2@demo.local', 'name' => 'Amina Bakari (Store)', 'role' => 'Storekeeper'],

        // Procurement
        ['email' => 'procurement@demo.local', 'name' => 'Eric Msuya (Procurement)', 'role' => 'Procurement Officer'],
        ['email' => 'procurement2@demo.local', 'name' => 'Lillian Temu (Procurement)', 'role' => 'Procurement Officer'],

        // HR
        ['email' => 'hr@demo.local', 'name' => 'Sarah Mrema (HR)', 'role' => 'HR Officer'],
        ['email' => 'hr2@demo.local', 'name' => 'Michael Tarimo (HR)', 'role' => 'HR Officer'],

        // Quantity Surveyors
        ['email' => 'qs@demo.local', 'name' => 'Paul Swai (QS)', 'role' => 'Quantity Surveyor'],
        ['email' => 'qs2@demo.local', 'name' => 'Joyce Mosha (QS)', 'role' => 'Quantity Surveyor'],

        // Auditors
        ['email' => 'auditor@demo.local', 'name' => 'Robert Lyimo (Auditor)', 'role' => 'Auditor'],
        ['email' => 'auditor2@demo.local', 'name' => 'Christina Mushi (Auditor)', 'role' => 'Auditor'],
    ];

    public function handle(PermissionService $permissionService): int
    {
        $identifier = $this->argument('tenant') ?? 'demo';
        $tenant = Tenant::where('slug', $identifier)->orWhere('id', $identifier)->first();

        if (! $tenant) {
            $this->error("Tenant not found: {$identifier}");

            return self::FAILURE;
        }

        $byRole = [];

        $tenant->run(function () use ($tenant, $permissionService, &$byRole) {
            $permissionService->syncTenantPermissions();

            foreach ($this->accounts as $account) {
                $user = User::firstOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => Hash::make('password'),
                    ],
                );

                // Keep names fresh if the account already existed from an older seed.
                if ($user->name !== $account['name']) {
                    $user->update(['name' => $account['name']]);
                }

                $user->syncRoles([$account['role']]);

                tenancy()->central(function () use ($tenant, $account) {
                    CentralUser::firstOrCreate(
                        ['email' => $account['email']],
                        ['tenant_id' => $tenant->id],
                    );
                });

                $byRole[$account['role']][] = $account['email'];
            }
        });

        $this->info('Demo users seeded. Password for all accounts: password');
        $this->newLine();
        $this->table(
            ['Role', 'Logins'],
            collect($byRole)->map(fn ($emails, $role) => [
                $role,
                implode(', ', $emails),
            ])->values()->all(),
        );
        $this->newLine();
        $this->line('Try these experiences:');
        $this->line('  • engineer@demo.local vs engineer2@demo.local — each only sees their own drafts');
        $this->line('  • Publish as engineer, then log in as pm@demo.local / finance@demo.local to approve');
        $this->line('  • storekeeper@demo.local — inventory / stock issue fulfillment');

        return self::SUCCESS;
    }
}
