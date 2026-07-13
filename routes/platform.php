<?php

use App\Http\Controllers\Platform\AuthController;
use App\Http\Controllers\Platform\DashboardController;
use App\Http\Controllers\Platform\TenantController;
use App\Http\Controllers\Platform\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('platform')->name('platform.')->group(function () {
    Route::middleware('guest:platform')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login']);
    });

    Route::middleware('platform.admin')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::get('/tenants/create', [TenantController::class, 'create'])->name('tenants.create');
        Route::post('/tenants', [TenantController::class, 'store'])->name('tenants.store');
        Route::get('/tenants/{tenantId}', [TenantController::class, 'show'])->name('tenants.show');
        Route::post('/tenants/{tenantId}/suspend', [TenantController::class, 'suspend'])->name('tenants.suspend');
        Route::post('/tenants/{tenantId}/reactivate', [TenantController::class, 'reactivate'])->name('tenants.reactivate');

        Route::get('/tenants/{tenantId}/users', [UserController::class, 'index'])->name('tenants.users');
        Route::post('/tenants/{tenantId}/users/{userId}/lock', [UserController::class, 'lock'])->name('tenants.users.lock');
        Route::post('/tenants/{tenantId}/users/{userId}/unlock', [UserController::class, 'unlock'])->name('tenants.users.unlock');
        Route::post('/tenants/{tenantId}/users/{userId}/impersonate', [UserController::class, 'impersonate'])->name('tenants.users.impersonate');
    });
});
