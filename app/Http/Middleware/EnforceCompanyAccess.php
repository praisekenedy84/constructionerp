<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\Services\CompanyAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class EnforceCompanyAccess
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('platform', 'platform/*', 'auth/reset-session') || ! $request->user()) {
            return $next($request);
        }
        $sessionRoutes = ['logout', 'companies.index', 'companies.select'];
        $name = $request->route()?->getName();
        if (session('company_selection_required') && ! in_array($name, $sessionRoutes, true)) {
            return redirect('/auth/companies');
        }
        if (! $request->isMethodSafe() && ! in_array($name, $sessionRoutes, true)
            && session('central_identity_id')
            && ($request->hasHeader('X-Company-Context') || CompanyAccessService::visiting() || count(app(CompanyAccessService::class)->companies()) > 1)) {
            if (count(session('verified_company_accounts', [])) > 1 || $request->hasHeader('X-Account-Context')) {
                abort_unless((string) $request->header('X-Account-Context') === (string) session('central_identity_id'), 409, 'Account changed. Reload this page.');
            }
            abort_unless($request->header('X-Company-Context') === session('tenant_id'), 409, 'Company changed. Reload this page.');
        }
        if (! CompanyAccessService::visiting() || in_array($name, $sessionRoutes, true)) {
            return $next($request);
        }
        if ($name === 'projects.select') {
            abort_unless($request->user()->hasModulePermission('projects', 'read'), 403);
            $project = Project::findOrFail($request->route('id'));
            session(['current_project_id' => $project->id]);

            return redirect('/projects/'.$project->id);
        }
        DB::connection('tenant')->beforeExecuting(function ($sql) {
            abort_unless(preg_match('/^\s*select\b/i', $sql), 403, 'Company visits cannot modify records.');
        });
        // Explicit viewing capabilities. Unknown routes fail closed.
        $module = match (true) {
            str_starts_with((string) $name, 'reports.') => 'reports',
            str_starts_with((string) $name, 'finance.') => 'budgets',
            str_starts_with((string) $name, 'procurement.') => 'procurement',
            str_starts_with((string) $name, 'payroll.') => 'payroll',
            str_starts_with((string) $name, 'audit.') => 'audit',
            str_starts_with((string) $name, 'projects.boq') => 'boq',
            str_starts_with((string) $name, 'projects.budget') => 'budgets',
            str_starts_with((string) $name, 'projects.valuations') => 'valuations',
            str_starts_with((string) $name, 'projects.') => 'projects',
            str_starts_with((string) $name, 'requisitions.') => 'requisitions',
            str_starts_with((string) $name, 'inventory.') => 'inventory',
            str_starts_with((string) $name, 'equipment.') => 'equipment',
            str_starts_with((string) $name, 'sales.') => 'sales',
            str_starts_with((string) $name, 'invoices.') => 'invoices',
            default => null,
        };
        if (str_starts_with((string) $name, 'reports.')) {
            foreach (['projects', 'boq', 'budgets', 'requisitions', 'inventory', 'payroll', 'equipment', 'valuations', 'sales', 'invoices'] as $required) {
                abort_unless($request->user()->hasModulePermission($required, 'read'), 403);
            }
        }
        $view = preg_match('/\.(index|show|tree|balances|items|hub|transactions|issues|runs|assignments|maintenance|fuel|overview|accounts|debts|manager-transactions|finance-transactions|approvals|expenses|overhead|preview)$/', (string) $name);
        if ($name === 'dashboard') {
            // The existing dashboard combines every module; avoid aggregate leaks.
            return Inertia::render('Auth/VisitorDashboard');
        }
        abort_unless($request->isMethodSafe() && $view && $module
            && $request->user()->hasModulePermission($module, 'read'), 403, 'This company is read-only. This action is unavailable.');

        return $next($request);
    }
}
