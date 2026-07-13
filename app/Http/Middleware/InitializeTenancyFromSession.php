<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class InitializeTenancyFromSession
{
    public function __construct(private AuthService $authService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('platform', 'platform/*')) {
            return $next($request);
        }

        if (session()->has('tenant_id')) {
            $this->authService->initializeTenancyFromSession();
        } elseif ($this->hasStaleAuthSession($request)) {
            Auth::guard('web')->logout();
            $request->session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);
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
