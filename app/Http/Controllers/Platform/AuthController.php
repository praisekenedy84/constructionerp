<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\PlatformAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function __construct(private PlatformAuthService $platformAuth) {}

    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        return Inertia::render('Platform/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ]);

        $this->platformAuth->login(
            $credentials['email'],
            $credentials['password'],
            $credentials['remember'] ?? false,
        );

        $request->session()->regenerate();

        return redirect()->intended(route('platform.dashboard'));
    }

    public function editPassword(): Response
    {
        return Inertia::render('Platform/Password');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:platform'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);
        Auth::guard('platform')->user()->update(['password' => $data['password']]);
        $request->session()->regenerate();

        return back()->with('success', 'Your password has been updated.');
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->platformAuth->logout();

        return redirect()->route('platform.login');
    }
}
