<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Tenant;
use App\Services\AuthService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RequestPerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected $connectionsToTransact = ['central'];

    public function test_authenticated_page_query_cost(): void
    {
        $tenant = Tenant::create(['name' => 'Performance Co', 'slug' => 'performance-co']);
        app(AuthService::class)->createUser($tenant, ['name' => 'Performance Admin', 'email' => 'performance@example.test', 'password' => 'password', 'role' => 'System Administrator']);
        tenancy()->end();
        $this->post('/login', ['username' => 'performance@example.test', 'password' => 'password'])->assertRedirect('/dashboard');

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = ['connection' => $query->connectionName, 'sql' => $query->sql];
        });
        $measurements = [];
        foreach (['/dashboard', '/projects', '/requisitions'] as $path) {
            $queries = [];
            $this->get($path)->assertOk();
            $this->assertEmpty(array_filter($queries, fn ($query) => str_contains($query['sql'], 'company_access_grants')),
                'Home-company requests should not enumerate company grants.');
            $measurements[$path] = ['queries' => count($queries), 'sql' => $queries];
        }
        if ($output = getenv('REQUEST_PERFORMANCE_OUTPUT')) {
            file_put_contents($output, json_encode($measurements, JSON_PRETTY_PRINT));
        }

        $queries = [];
        $this->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Dashboard',
            'X-Inertia-Partial-Data' => 'stats',
        ])->get('/dashboard')->assertOk()->assertJsonStructure(['props' => ['stats']])
            ->assertJsonMissingPath('props.unreadNotificationCount');
        $this->assertEmpty(array_filter($queries, fn ($query) => str_contains($query['sql'], 'notifications')),
            'A stats-only reload should not count notifications.');
    }
}
