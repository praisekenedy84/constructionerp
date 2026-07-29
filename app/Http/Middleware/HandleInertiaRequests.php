<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\PlatformAdmin;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Services\MenuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        // Prefer an explicit deploy stamp so PHP-only releases still invalidate
        // stale browser tabs. Fall back to the Vite manifest hash.
        $deployVersion = config('app.asset_version');

        if (is_string($deployVersion) && $deployVersion !== '') {
            return $deployVersion;
        }

        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $isPlatform = $request->is('platform', 'platform/*');
        $platformAdmin = Auth::guard('platform')->user();
        $uiSettings = ['app_name' => 'CRF-ERP', 'tagline' => 'Construction Resource & Finance'];

        // Platform controllers briefly initialize then end tenancy. Resolving the
        // web user here would re-init tenancy (via TenantAwareUserProvider) and
        // leave shared Eloquent models pointing at a purged `tenant` connection.
        if ($isPlatform) {
            return [
                ...parent::share($request),
                'auth' => [
                    'user' => null,
                    'platform_admin' => $platformAdmin instanceof PlatformAdmin ? [
                        'id' => $platformAdmin->id,
                        'name' => $platformAdmin->name,
                        'email' => $platformAdmin->email,
                    ] : null,
                    'impersonator_id' => session('impersonator_id'),
                    'platform_impersonator_id' => session('platform_impersonator_id'),
                ],
                'currentProject' => null,
                'unreadNotificationCount' => 0,
                'uiSettings' => $uiSettings,
                'navigation' => [],
                'flash' => [
                    'success' => fn () => $request->session()->get('success'),
                    'error' => fn () => $request->session()->get('error'),
                ],
            ];
        }

        $user = $request->user();

        if ($user && tenancy()->initialized) {
            $setting = SystemSetting::where('key', 'ui_settings')->first();
            if ($setting) {
                $uiSettings = $setting->value;
            }
        }

        // Share a scalar/array — never a Model — so serialization cannot hit a
        // purged tenant connection after platform code calls tenancy()->end().
        $currentProjectId = null;
        if ($user && tenancy()->initialized && session('current_project_id')) {
            $currentProjectId = Project::whereKey(session('current_project_id'))->value('id');
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames()->values()->all(),
                    'permissions' => $user->modulePermissions(),
                    'can_manage_platform' => $user->canManagePlatform(),
                    'can_impersonate' => $user->canImpersonate(),
                ] : null,
                'platform_admin' => $platformAdmin instanceof PlatformAdmin ? [
                    'id' => $platformAdmin->id,
                    'name' => $platformAdmin->name,
                    'email' => $platformAdmin->email,
                ] : null,
                'impersonator_id' => session('impersonator_id'),
                'platform_impersonator_id' => session('platform_impersonator_id'),
            ],
            'currentProject' => $currentProjectId,
            'unreadNotificationCount' => $user && tenancy()->initialized
                ? Notification::where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
            'uiSettings' => $uiSettings,
            'navigation' => $user && tenancy()->initialized
                ? app(MenuService::class)->visibleForUser($user, $uiSettings)
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
