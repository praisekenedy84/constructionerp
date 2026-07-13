<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Services\AuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Auth::provider('tenant_eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider(
                $app['hash'],
                $config['model'],
                $app->make(AuthService::class),
            );
        });

        Gate::before(function ($user, $ability) {
            if (! method_exists($user, 'isSuperUser')) {
                return null;
            }

            if ($user->isSuperUser()) {
                return true;
            }

            return null;
        });
    }
}
