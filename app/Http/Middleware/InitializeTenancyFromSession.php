<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyFromSession
{
    public function __construct(private AuthService $authService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('platform', 'platform/*', 'auth/reset-session')) {
            return $next($request);
        }

        if (session()->has('home_tenant_id') && (session('auth_context_version') !== 2
            || ! is_array(session('verified_company_accounts')))) {
            $this->authService->clearTenantSession();

            return redirect()->route('login')->withErrors(['username' => 'Please sign in again to continue with the updated application.']);
        }

        if (session()->has('tenant_id')) {
            $this->authService->initializeTenancyFromSession();
        } elseif ($this->hasStaleAuthSession($request)) {
            $this->authService->clearTenantSession();
        }

        return $next($request);
    }

    private function hasStaleAuthSession(Request $request): bool
    {
        foreach (array_keys($request->session()->all()) as $key) {
            if (str_starts_with($key, 'login_web_')) {
                return true;
            }
        }

        return false;
    }
}
