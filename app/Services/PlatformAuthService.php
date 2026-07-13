<?php

namespace App\Services;

use App\Models\PlatformAdmin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PlatformAuthService
{
    public function login(string $email, string $password, bool $remember = false): PlatformAdmin
    {
        $admin = PlatformAdmin::where('email', $email)->first();

        if (! $admin || ! $admin->canAccessPlatform()) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! Auth::guard('platform')->attempt(
            ['email' => $email, 'password' => $password, 'is_active' => true],
            $remember,
        )) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        return Auth::guard('platform')->user();
    }

    public function logout(): void
    {
        Auth::guard('platform')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
