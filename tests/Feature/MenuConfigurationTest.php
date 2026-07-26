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

                    return $finance['href'] === '/finance/approvals'
                        && ($finance['active_path'] ?? null) === '/finance'
                        && $childHrefs === [
                            '/finance/approvals',
                            '/finance/expenses',
                            '/finance/overhead',
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
            ['/finance/approvals', '/finance/expenses'],
            collect($finance['children'] ?? [])->pluck('href')->all(),
        );
    }
}
