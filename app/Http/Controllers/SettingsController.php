<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function ui(Request $request): Response
    {
        if (! $request->user()?->canManagePlatform()) {
            abort(403);
        }

        $setting = SystemSetting::where('key', 'ui_settings')->first();
        $uiSettings = $setting?->value ?? [
            'app_name' => 'CRF-ERP',
            'tagline' => 'Construction Resource & Finance',
        ];

        return Inertia::render('Admin/Settings', [
            'ui_settings' => $uiSettings,
        ]);
    }
}
