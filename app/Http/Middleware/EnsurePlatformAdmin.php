<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('platform')->check()) {
            return redirect()->route('platform.login');
        }

        $admin = Auth::guard('platform')->user();

        if (! $admin->canAccessPlatform()) {
            Auth::guard('platform')->logout();

            return redirect()->route('platform.login')
                ->withErrors(['email' => 'Your platform administrator account is inactive.']);
        }

        return $next($request);
    }
}
