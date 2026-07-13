<?php

namespace App\Http\Middleware;

use App\Services\TenantManagementService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PreventLockedOrSuspendedAccess
{
    public function __construct(private TenantManagementService $tenantManagement) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('platform', 'platform/*')) {
            return $next($request);
        }

        if (! Auth::guard('web')->check() || ! tenancy()->initialized) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();

        if ($user->isLocked()) {
            Auth::guard('web')->logout();
            $request->session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);

            return redirect()->route('login')
                ->withErrors(['email' => 'Your account has been locked. Contact your administrator.']);
        }

        $tenant = tenant();

        if ($tenant && $tenant->isSuspended()) {
            Auth::guard('web')->logout();
            $request->session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);

            return redirect()->route('login')
                ->withErrors(['email' => 'This company account has been suspended.']);
        }

        return $next($request);
    }
}
