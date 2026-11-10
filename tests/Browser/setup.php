<?php

use App\Models\PlatformAdmin;
use App\Models\Project;
use App\Models\Recipient;
use App\Models\Requisition;
use App\Models\Unit;
use App\Services\TenantManagementService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! str_contains(config('database.connections.sqlite.database'), 'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'playwright')) {
    throw new RuntimeException('Browser tests require a separate Playwright database.');
}
Artisan::call('migrate', ['--force' => true]);
PlatformAdmin::create(['name' => 'Browser Platform Admin', 'email' => 'platform@browser.test', 'password' => 'BrowserPass123!', 'is_active' => true]);
$tenant = app(TenantManagementService::class)->provision([
    'name' => 'Browser Demo Construction', 'slug' => 'browser-demo',
    'admin_name' => 'Browser Tenant Admin', 'admin_email' => 'admin@browser.test',
    'admin_username' => 'browser.admin', 'admin_password' => 'BrowserPass123!',
]);
foreach (['tenant:seed-demo', 'tenant:seed-users'] as $command) {
    if (Artisan::call($command, ['tenant' => $tenant->id]) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
}
$records = $tenant->run(function () {
    $recipient = Recipient::create(['name' => 'Browser Recipient', 'phone' => '+254700111222', 'status' => 'active']);
    $project = Project::where('code', 'DEMO-001')->firstOrFail();
    $project->recipients()->attach($recipient->id);
    Unit::firstOrCreate(['name' => 'pcs'], ['is_active' => true, 'sort_order' => 0]);

    return [
        'projectId' => $project->id,
        'requisitions' => Requisition::get(['id', 'status'])->toArray(),
    ];
});
$routes = collect(app('router')->getRoutes())->filter(fn ($route) => in_array('GET', $route->methods())
    && in_array('auth', $route->gatherMiddleware())
    && ! str_contains($route->uri(), '{')
    && ! preg_match('~/export|^me$|notifications/unread-count~', $route->uri()))
    ->map(fn ($route) => '/'.$route->uri())->values()->all();
$manifest = ['tenantId' => $tenant->id, ...$records, 'routes' => $routes];
file_put_contents(__DIR__.'/../../storage/app/playwright/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
echo 'Browser fixtures ready: '.count($routes)." static tenant routes.\n";
