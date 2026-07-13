<?php

namespace App\Jobs;

use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\WorkflowConfig;
use App\Services\PermissionService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Permission\Models\Role;

class SeedTenantDefaults
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Tenant $tenant) {}

    public function handle(): void
    {
        $this->tenant->run(function () {
            $roles = [
                'Platform Admin',
                'System Administrator',
                'Managing Director',
                'Manager',
                'Finance Manager',
                'Accountant',
                'Quantity Surveyor',
                'Procurement Officer',
                'Storekeeper',
                'Project Manager',
                'Site Engineer',
                'HR Officer',
                'Auditor',
            ];

            foreach ($roles as $role) {
                Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
            }

            $thresholds = [
                ['level' => 1, 'role_name' => 'Project Manager', 'threshold_min' => 0, 'threshold_max' => 500000],
                ['level' => 2, 'role_name' => 'Finance Manager', 'threshold_min' => 500001, 'threshold_max' => 5000000],
                ['level' => 3, 'role_name' => 'Managing Director', 'threshold_min' => 5000001, 'threshold_max' => null],
            ];

            foreach ($thresholds as $config) {
                WorkflowConfig::firstOrCreate(
                    ['project_id' => null, 'level' => $config['level']],
                    array_merge($config, ['escalation_hours' => 48]),
                );
            }

            SystemSetting::updateOrCreate(
                ['key' => 'ui_settings'],
                [
                    'value' => [
                        'app_name' => 'CRF-ERP',
                        'tagline' => 'Construction Resource & Finance',
                        'nav_overrides' => [
                            'hidden' => [],
                            'role_hidden' => [],
                        ],
                    ],
                    'updated_at' => now(),
                ],
            );

            app(PermissionService::class)->syncTenantPermissions();
        });
    }
}
