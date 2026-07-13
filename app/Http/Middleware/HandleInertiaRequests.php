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
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $platformAdmin = Auth::guard('platform')->user();
        $uiSettings = ['app_name' => 'CRF-ERP', 'tagline' => 'Construction Resource & Finance'];

        if ($user && tenancy()->initialized) {
            $setting = SystemSetting::where('key', 'ui_settings')->first();
            if ($setting) {
                $uiSettings = $setting->value;
            }
        }

        $currentProject = null;
        if ($user && tenancy()->initialized && session('current_project_id')) {
            $currentProject = Project::find(session('current_project_id'));
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
            'currentProject' => $currentProject,
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
