<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\AuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private AuditService $auditService,
    ) {}

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $this->authService->login(
            $credentials['email'],
            $credentials['password'],
            $credentials['remember'] ?? false,
        );

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->authService->logout();

        return redirect()->route('login');
    }

    public function me(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames(),
            ],
        ]);
    }

    public function impersonate(Request $request, int $userId): RedirectResponse
    {
        if (! $request->user()->canImpersonate()) {
            abort(403, 'You do not have permission to impersonate users.');
        }

        $target = User::findOrFail($userId);

        if ($target->hasRole('Platform Admin') || $target->id === $request->user()->id) {
            abort(403, 'Cannot impersonate this user.');
        }

        $this->auditService->write(
            'User',
            $target->id,
            'impersonate',
            null,
            ['impersonator_id' => $request->user()->id, 'target_id' => $target->id],
            $request->user()->id,
        );

        session(['impersonator_id' => $request->user()->id]);
        Auth::login($target);

        return redirect()->route('dashboard')->with('success', "Now impersonating {$target->name}.");
    }

    public function exitImpersonation(Request $request): RedirectResponse
    {
        if (session('platform_impersonator_id')) {
            $platformAdminId = session('platform_impersonator_id');

            Auth::guard('web')->logout();
            session()->forget(['tenant_id', 'impersonator_id', 'platform_impersonator_id']);

            if (tenancy()->initialized) {
                tenancy()->end();
            }

            Auth::guard('platform')->loginUsingId($platformAdminId);

            return redirect()->route('platform.dashboard')->with('success', 'Returned to platform administration.');
        }

        $impersonatorId = session('impersonator_id');

        if (! $impersonatorId) {
            return redirect()->route('dashboard');
        }

        $impersonator = User::findOrFail($impersonatorId);

        session()->forget('impersonator_id');
        Auth::login($impersonator);

        return redirect()->route('dashboard')->with('success', 'Returned to your account.');
    }
}
