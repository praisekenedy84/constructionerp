<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use App\Models\PlatformAdmin;
use App\Models\Project;
use App\Models\SystemSetting;
use App\Services\CompanyAccessService;
use App\Services\MenuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function handle(Request $request, \Closure $next)
    {
        if ($request->is('auth/reset-session')) {
            return $next($request);
        }
        $response = parent::handle($request, $next);
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $response;
    }

    public function version(Request $request): ?string
    {
        // Prefer an explicit deploy stamp so PHP-only releases still invalidate
        // stale browser tabs. Fall back to the Vite manifest hash.
        $deployVersion = config('app.asset_version');

        $manifest = public_path('build/manifest.json');
        $assets = is_file($manifest) ? hash_file('sha256', $manifest) : parent::version($request);

        return hash('sha256', (string) $deployVersion.'|'.(string) $assets);
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

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'read_only' => CompanyAccessService::visiting(),
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'phone' => $user->phone,
                    'roles' => $user->getRoleNames()->values()->all(),
                    'permissions' => $user->modulePermissions(),
                    'can_manage_platform' => ! CompanyAccessService::visiting() && $user->canManagePlatform(),
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
            'companyContext' => ['id' => session('tenant_id'), 'name' => tenant('name'), 'account_id' => session('central_identity_id'), 'read_only' => CompanyAccessService::visiting()],
            // Resolve these only when included in the Inertia response. Redirects
            // and partial reloads should not repeat unrelated shared queries.
            'currentProject' => fn () => $user && tenancy()->initialized && session('current_project_id')
                ? Project::whereKey(session('current_project_id'))->value('id')
                : null,
            'unreadNotificationCount' => fn () => $user && ! CompanyAccessService::visiting() && tenancy()->initialized
                ? Notification::where('user_id', $user->id)->whereNull('read_at')->count()
                : 0,
            'uiSettings' => $uiSettings,
            'navigation' => fn () => $user && tenancy()->initialized
                ? app(MenuService::class)->visibleForUser($user, $uiSettings)
                : [],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
