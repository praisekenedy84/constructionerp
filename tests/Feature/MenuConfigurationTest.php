<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Services\AuthService;
use App\Services\MenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_hide_menu_item_for_role(): void
    {
        $tenant = Tenant::create(['name' => 'Menu Co', 'slug' => 'menu-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Tenant Admin',
            'email' => 'admin@menu-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Storekeeper',
            'email' => 'store@menu-co.local',
            'password' => 'password',
            'role' => 'Storekeeper',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@menu-co.local',
            'password' => 'password',
        ]);

        $this->post('/admin/menu', [
            'role_hidden' => [
                'Storekeeper' => ['/inventory'],
            ],
        ])->assertRedirect();

        auth()->logout();

        $this->post('/login', [
            'email' => 'store@menu-co.local',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('navigation')
                ->where('navigation', fn ($nav) => ! collect($nav)->contains('href', '/inventory'))
            );
    }

    public function test_hidden_menu_does_not_block_direct_url_access(): void
    {
        $tenant = Tenant::create(['name' => 'Menu Co 2', 'slug' => 'menu-co-2']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Tenant Admin',
            'email' => 'admin2@menu-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin2@menu-co.local',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            SystemSetting::updateOrCreate(
                ['key' => 'ui_settings'],
                [
                    'value' => [
                        'app_name' => 'CRF-ERP',
                        'tagline' => 'Test',
                        'nav_overrides' => [
                            'role_hidden' => [
                                'System Administrator' => ['/reports'],
                            ],
                        ],
                    ],
                    'updated_at' => now(),
                ],
            );
        });

        $user = auth()->user();
        $uiSettings = $tenant->run(fn () => SystemSetting::where('key', 'ui_settings')->first()->value);

        $nav = app(MenuService::class)->visibleForUser($user, $uiSettings);
        $this->assertFalse(collect($nav)->contains('href', '/reports'));

        $this->get('/reports')->assertOk();
    }

    public function test_finance_menu_includes_submenu_children(): void
    {
        $tenant = Tenant::create(['name' => 'Nav Co', 'slug' => 'nav-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Finance User',
            'email' => 'finance@nav-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'finance@nav-co.local',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('navigation')
                ->where('navigation', function ($nav) {
                    $finance = collect($nav)->firstWhere('key', 'finance');

                    if (! $finance) {
                        return false;
                    }

                    $childHrefs = collect($finance['children'] ?? [])->pluck('href')->all();

                    return $finance['href'] === '/finance/overview'
                        && ($finance['active_path'] ?? null) === '/finance'
                        && $childHrefs === [
                            '/finance/overview',
                            '/finance/approvals',
                            '/finance/organization-cash',
                            '/finance/expenses',
                            '/finance/overhead',
                        ];
                })
            );
    }

    public function test_requisitions_menu_includes_submenu_children(): void
    {
        $tenant = Tenant::create(['name' => 'Req Nav Co', 'slug' => 'req-nav-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@req-nav-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@req-nav-co.local',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('navigation')
                ->where('navigation', function ($nav) {
                    $requisitions = collect($nav)->firstWhere('key', 'requisitions');

                    if (! $requisitions) {
                        return false;
                    }

                    $children = collect($requisitions['children'] ?? []);
                    $childHrefs = $children->pluck('href')->all();
                    $childLabels = $children->pluck('label')->all();

                    return $requisitions['href'] === '/requisitions'
                        && ($requisitions['active_path'] ?? null) === '/requisitions'
                        && $childLabels === [
                            'New Requisition',
                            'Requisition List',
                            'Categories',
                            'Departments',
                            'Positions',
                            'Review Queue',
                            'Fulfill Queue',
                            'Fulfilled List',
                        ]
                        && $childHrefs === [
                            '/requisitions/create',
                            '/requisitions',
                            '/requisitions/categories',
                            '/requisitions/departments',
                            '/requisitions/positions',
                            '/requisitions/review-queue',
                            '/requisitions/fulfill-queue',
                            '/requisitions/fulfilled',
                        ];
                })
            );
    }

    public function test_requisitions_submenu_respects_child_permissions(): void
    {
        $tenant = Tenant::create(['name' => 'Req Perm Co', 'slug' => 'req-perm-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Engineer',
            'email' => 'engineer@req-perm-co.local',
            'password' => 'password',
            'role' => 'Site Engineer',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'engineer@req-perm-co.local',
            'password' => 'password',
        ]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('navigation')
                ->where('navigation', function ($nav) {
                    $requisitions = collect($nav)->firstWhere('key', 'requisitions');

                    if (! $requisitions) {
                        return false;
                    }

                    $childHrefs = collect($requisitions['children'] ?? [])->pluck('href')->all();

                    // Site Engineer: create + read — no approve/fulfill queues
                    return $childHrefs === [
                        '/requisitions/create',
                        '/requisitions',
                        '/requisitions/categories',
                        '/requisitions/departments',
                        '/requisitions/positions',
                        '/requisitions/fulfilled',
                    ];
                })
            );
    }

    public function test_hidden_child_menu_item_is_omitted_from_navigation(): void
    {
        $tenant = Tenant::create(['name' => 'Child Hide Co', 'slug' => 'child-hide-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@child-hide.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@child-hide.local',
            'password' => 'password',
        ]);

        $tenant->run(function () {
            SystemSetting::updateOrCreate(
                ['key' => 'ui_settings'],
                [
                    'value' => [
                        'app_name' => 'CRF-ERP',
                        'tagline' => 'Test',
                        'nav_overrides' => [
                            'role_hidden' => [
                                'System Administrator' => ['/finance/overhead'],
                            ],
                        ],
                    ],
                    'updated_at' => now(),
                ],
            );
        });

        $user = auth()->user();
        $uiSettings = $tenant->run(fn () => SystemSetting::where('key', 'ui_settings')->first()->value);

        $nav = app(MenuService::class)->visibleForUser($user, $uiSettings);
        $finance = collect($nav)->firstWhere('key', 'finance');

        $this->assertNotNull($finance);
        $this->assertSame(
            [
                '/finance/overview',
                '/finance/approvals',
                '/finance/organization-cash',
                '/finance/expenses',
            ],
            collect($finance['children'] ?? [])->pluck('href')->all(),
        );
    }

    public function test_tenant_admin_can_reorder_menu_and_submenu_items(): void
    {
        $tenant = Tenant::create(['name' => 'Order Co', 'slug' => 'order-co']);

        app(AuthService::class)->createUser($tenant, [
            'name' => 'Admin',
            'email' => 'admin@order-co.local',
            'password' => 'password',
            'role' => 'System Administrator',
        ]);

        tenancy()->end();

        $this->post('/login', [
            'email' => 'admin@order-co.local',
            'password' => 'password',
        ]);

        $this->post('/admin/menu', [
            'hidden' => [],
            'role_hidden' => [],
            'order' => [
                'requisitions',
                'dashboard',
                'projects',
                'finance',
                'procurement',
                'inventory',
                'payroll',
                'equipment',
                'reports',
                'audit',
                'admin',
            ],
            'child_order' => [
                'requisitions' => [
                    'requisitions.list',
                    'requisitions.new',
                    'requisitions.categories',
                    'requisitions.departments',
                    'requisitions.positions',
                    'requisitions.review_queue',
                    'requisitions.fulfill_queue',
                    'requisitions.fulfilled',
                ],
            ],
        ])->assertRedirect();

        $this->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('navigation')
                ->where('navigation', function ($nav) {
                    $keys = collect($nav)->pluck('key')->all();
                    $requisitions = collect($nav)->firstWhere('key', 'requisitions');
                    $childKeys = collect($requisitions['children'] ?? [])->pluck('key')->all();

                    return ($keys[0] ?? null) === 'requisitions'
                        && ($keys[1] ?? null) === 'dashboard'
                        && ($childKeys[0] ?? null) === 'requisitions.list'
                        && ($childKeys[1] ?? null) === 'requisitions.new';
                })
            );
    }
}
