<?php

use App\Http\Middleware\EnforceCompanyAccess;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\InitializeTenancyFromSession;
use App\Http\Middleware\PreventLockedOrSuspendedAccess;
use App\Http\Middleware\SetCurrentProject;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('web')
                ->group(base_path('routes/platform.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Restore or discard tenant identity before Laravel resolves the authenticated user.
        $middleware->appendToPriorityList(ShareErrorsFromSession::class, InitializeTenancyFromSession::class);
        $middleware->alias([
            'project.context' => SetCurrentProject::class,
            'platform.admin' => EnsurePlatformAdmin::class,
        ]);

        $middleware->web(append: [
            InitializeTenancyFromSession::class,
            PreventLockedOrSuspendedAccess::class,
            HandleInertiaRequests::class,
            EnforceCompanyAccess::class,
        ]);

        $middleware->redirectGuestsTo(fn ($request) => $request->is('platform', 'platform/*')
            ? route('platform.login')
            : route('login'));
        $middleware->redirectUsersTo(fn ($request) => $request->is('platform', 'platform/*')
            ? route('platform.dashboard')
            : route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 419 || ($response->getStatusCode() === 409 && $request->header('X-Inertia'))) {
                $platform = $request->is('platform', 'platform/*');
                $destination = $response->getStatusCode() === 419
                    ? route($platform ? 'platform.login' : 'login')
                    : route($platform ? 'platform.dashboard' : 'dashboard');

                return $request->header('X-Inertia')
                    ? Inertia::location($destination)
                    : redirect()->to($destination);
            }
            // Keep users off POST-only URLs after failed Inertia submissions.
            if (
                $request->header('X-Inertia')
                && ! $request->isMethodSafe()
                && in_array($response->getStatusCode(), [403, 404, 500, 503], true)
            ) {
                $fallback = $request->headers->get('referer') ?: route('dashboard');
                $message = $exception->getMessage();

                return redirect()
                    ->to($fallback)
                    ->with('error', $message !== '' ? $message : 'Something went wrong.');
            }

            if (! in_array($response->getStatusCode(), [403, 404, 500, 503], true)) {
                return $response;
            }

            if ($request->header('X-Inertia')) {
                $message = $exception->getMessage();

                return Inertia::render('Error', [
                    'status' => $response->getStatusCode(),
                    'message' => $message !== '' ? $message : null,
                ])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            if (
                in_array($response->getStatusCode(), [403, 404], true)
                && ($request->user() || $request->user('platform'))
            ) {
                $message = $exception->getMessage();

                return Inertia::render('Error', [
                    'status' => $response->getStatusCode(),
                    'message' => $message !== '' ? $message : null,
                ])
                    ->toResponse($request)
                    ->setStatusCode($response->getStatusCode());
            }

            return $response;
        });
    })->create();
