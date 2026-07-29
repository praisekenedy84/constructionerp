<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // A leftover public/hot from local `npm run dev` makes @vite point at
        // the HMR server. In production that URL is unreachable → blank pages.
        if ($this->app->environment('production')) {
            Vite::useHotFile(storage_path('framework/vite-hot-never'));
        }

        Auth::provider('tenant_eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider(
                $app['hash'],
                $config['model'],
                $app->make(AuthService::class),
            );
        });

        Gate::before(function ($user, $ability) {
            if (! method_exists($user, 'isPlatformAdmin')) {
                return null;
            }

            // Only Platform Admin bypasses Gates; tenant access is checkbox-driven.
            if ($user->isPlatformAdmin()) {
                return true;
            }

            return null;
        });
    }
}
