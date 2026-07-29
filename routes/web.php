<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BOQController;
use App\Http\Controllers\BOQRevisionController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HubController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\ValuationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/me', [AuthController::class, 'me'])->name('me');
    Route::post('/auth/impersonate/{userId}', [AuthController::class, 'impersonate'])->name('auth.impersonate');
    Route::post('/auth/exit-impersonation', [AuthController::class, 'exitImpersonation'])->name('auth.exit-impersonation');

    Route::get('/projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::get('/projects/create', [ProjectController::class, 'create'])->name('projects.create');
    Route::post('/projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::post('/projects/{id}/select', [ProjectController::class, 'select'])->name('projects.select');
    Route::get('/projects/{id}/edit', [ProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/projects/{id}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::middleware('project.context')->group(function () {
        Route::get('/projects/{id}', [ProjectController::class, 'show'])->name('projects.show');
        Route::patch('/projects/{id}/progress', [ProjectController::class, 'updateProgress'])->name('projects.progress');

        Route::get('/projects/{id}/boq', [BOQController::class, 'tree'])->name('projects.boq');
        Route::get('/projects/{id}/boq/create', [BOQController::class, 'create'])->name('projects.boq.create');
        Route::post('/projects/{id}/boq/items', [BOQController::class, 'store'])->name('projects.boq.store');
        Route::post('/projects/{id}/boq/items/bulk-delete', [BOQController::class, 'bulkDestroy'])->name('projects.boq.items.bulk-destroy');
        Route::get('/projects/{id}/boq/items/{itemId}/edit', [BOQController::class, 'edit'])->name('projects.boq.items.edit');
        Route::put('/projects/{id}/boq/items/{itemId}', [BOQController::class, 'update'])->name('projects.boq.items.update');
        Route::delete('/projects/{id}/boq/items/{itemId}', [BOQController::class, 'destroy'])->name('projects.boq.items.destroy');
        Route::get('/projects/{id}/boq/import', [BOQController::class, 'importForm'])->name('projects.boq.import-form');
        Route::post('/projects/{id}/boq/import', [BOQController::class, 'import'])->name('projects.boq.import');
        Route::get('/projects/{id}/boq/export', [BOQController::class, 'export'])->name('projects.boq.export');

        Route::get('/projects/{id}/budget', [BudgetController::class, 'show'])->name('projects.budget');
        Route::post('/projects/{id}/budget/adjustment', [BudgetController::class, 'manualAdjustment'])->name('projects.budget.adjustment');

        Route::get('/projects/{id}/valuations', [ValuationController::class, 'index'])->name('projects.valuations.index');
        Route::get('/projects/{id}/valuations/create', [ValuationController::class, 'create'])->name('projects.valuations.create');
        Route::post('/projects/{id}/valuations', [ValuationController::class, 'store'])->name('projects.valuations.store');
    });

    Route::post('/boq/revisions', [BOQRevisionController::class, 'store'])->name('boq.revisions.store');
    Route::post('/boq/revisions/{id}/activate', [BOQRevisionController::class, 'activate'])->name('boq.revisions.activate');

    Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('/requisitions/create', [RequisitionController::class, 'create'])->name('requisitions.create');
    Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('/requisitions/review-queue', [RequisitionController::class, 'reviewQueue'])->name('requisitions.review-queue');
    Route::get('/requisitions/fulfill-queue', [RequisitionController::class, 'fulfillQueue'])->name('requisitions.fulfill-queue');
    Route::get('/requisitions/{id}/edit', [RequisitionController::class, 'edit'])->name('requisitions.edit');
    Route::put('/requisitions/{id}', [RequisitionController::class, 'update'])->name('requisitions.update');
    Route::get('/requisitions/{id}', [RequisitionController::class, 'show'])->name('requisitions.show');
    Route::post('/requisitions/{id}/transition', [RequisitionController::class, 'transition'])->name('requisitions.transition');
    Route::post('/requisitions/{id}/attachments', [RequisitionController::class, 'addAttachment'])->name('requisitions.attachments');
    Route::delete('/requisitions/{id}', [RequisitionController::class, 'destroy'])->name('requisitions.destroy');

    Route::get('/approvals/steps', [ApprovalController::class, 'steps'])->name('approvals.steps');
    Route::get('/approvals/steps/{id}/resolve', function () {
        return redirect()
            ->route('requisitions.review-queue')
            ->with('error', 'Use the review form to approve or reject a requisition.');
    })->name('approvals.resolve.get');
    Route::post('/approvals/steps/{id}/resolve', [ApprovalController::class, 'resolve'])->name('approvals.resolve');

    Route::get('/finance', [HubController::class, 'finance'])->name('finance.hub');
    Route::get('/finance/approvals', [CashController::class, 'fundApprovals'])->name('finance.approvals');
    Route::get('/finance/approvals/export', [CashController::class, 'exportFundApprovals'])->name('finance.approvals.export');
    Route::post('/finance/cash-requests', [CashController::class, 'request'])->name('finance.cash.request');
    Route::post('/finance/cash-requests/{id}/approve', [CashController::class, 'approve'])->name('finance.cash.approve');
    Route::post('/finance/cash-requests/{id}/reject', [CashController::class, 'reject'])->name('finance.cash.reject');
    Route::post('/finance/cash-requests/{id}/receive', [CashController::class, 'receive'])->name('finance.cash.receive');
    Route::post('/finance/expenses', [ExpenseController::class, 'store'])->name('finance.expenses.store');
    Route::get('/finance/expenses', [ExpenseController::class, 'index'])->name('finance.expenses.index');
    Route::get('/finance/overhead', [ExpenseController::class, 'overhead'])->name('finance.overhead');

    Route::middleware('project.context')->group(function () {
        Route::get('/finance/reconciliation/{projectId}', [CashController::class, 'reconciliation'])
            ->whereNumber('projectId')
            ->name('finance.reconciliation');
        Route::get('/finance/{projectId}/cash-flow', [CashController::class, 'cashFlow'])
            ->whereNumber('projectId')
            ->name('finance.cash-flow');
        Route::get('/finance/{projectId}', [FinanceController::class, 'dashboard'])
            ->whereNumber('projectId')
            ->name('finance.dashboard');
    });

    Route::get('/procurement', [HubController::class, 'procurement'])->name('procurement.hub');
    Route::get('/procurement/suppliers', [SupplierController::class, 'index'])->name('procurement.suppliers.index');
    Route::post('/procurement/suppliers', [SupplierController::class, 'store'])->name('procurement.suppliers.store');
    Route::get('/procurement/purchase-orders', [PurchaseOrderController::class, 'index'])->name('procurement.purchase-orders.index');
    Route::post('/procurement/purchase-orders', [PurchaseOrderController::class, 'store'])->name('procurement.purchase-orders.store');
    Route::get('/procurement/goods-receipts', [GoodsReceiptController::class, 'index'])->name('procurement.goods-receipts.index');
    Route::post('/procurement/goods-receipts', [GoodsReceiptController::class, 'store'])->name('procurement.goods-receipts.store');

    Route::get('/inventory', [HubController::class, 'inventory'])->name('inventory.hub');
    Route::get('/inventory/items', [InventoryController::class, 'items'])->name('inventory.items');
    Route::post('/inventory/items', [InventoryController::class, 'storeItem'])->name('inventory.items.store');
    Route::post('/inventory/locations', [InventoryController::class, 'storeLocation'])->name('inventory.locations.store');
    Route::get('/inventory/balances', [InventoryController::class, 'balances'])->name('inventory.balances');
    Route::get('/inventory/issues', [InventoryController::class, 'issues'])->name('inventory.issues');
    Route::get('/inventory/transactions', [InventoryController::class, 'transactions'])->name('inventory.transactions');
    Route::post('/inventory/issue', [InventoryController::class, 'issue'])->name('inventory.issue');
    Route::post('/inventory/receive', [InventoryController::class, 'receive'])->name('inventory.receive');
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer'])->name('inventory.transfer');
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');

    Route::get('/payroll', [HubController::class, 'payroll'])->name('payroll.hub');
    Route::post('/payroll/generate', [PayrollController::class, 'generate'])->name('payroll.generate');
    Route::get('/payroll/generate', [PayrollController::class, 'generateForm'])->name('payroll.generate.form');
    Route::get('/payroll/runs', [PayrollController::class, 'runs'])->name('payroll.runs');
    Route::get('/payroll/runs/{id}', [PayrollController::class, 'show'])->name('payroll.runs.show')->whereNumber('id');
    Route::post('/payroll/{id}/post', [PayrollController::class, 'post'])->name('payroll.post')->whereNumber('id');
    Route::get('/payroll/employees', [EmployeeController::class, 'index'])->name('payroll.employees.index');
    Route::post('/payroll/employees', [EmployeeController::class, 'store'])->name('payroll.employees.store');
    Route::patch('/payroll/employees/{id}', [EmployeeController::class, 'update'])->name('payroll.employees.update');
    Route::delete('/payroll/employees/{id}', [EmployeeController::class, 'destroy'])->name('payroll.employees.destroy');
    Route::get('/payroll/attendance', [AttendanceController::class, 'index'])->name('payroll.attendance.index');
    Route::post('/payroll/attendance', [AttendanceController::class, 'store'])->name('payroll.attendance.store');

    Route::middleware('project.context')->group(function () {
        Route::get('/payroll/{projectId}', [PayrollController::class, 'index'])
            ->whereNumber('projectId')
            ->name('payroll.index');
    });

    Route::get('/equipment', [EquipmentController::class, 'index'])->name('equipment.index');
    Route::post('/equipment', [EquipmentController::class, 'store'])->name('equipment.store');
    Route::get('/equipment/assignments', [EquipmentController::class, 'assignments'])->name('equipment.assignments');
    Route::post('/equipment/assignments', [EquipmentController::class, 'assign'])->name('equipment.assignments.store');
    Route::get('/equipment/maintenance', [EquipmentController::class, 'maintenanceIndex'])->name('equipment.maintenance');
    Route::post('/equipment/maintenance', [EquipmentController::class, 'maintenance'])->name('equipment.maintenance.store');
    Route::get('/equipment/fuel', [EquipmentController::class, 'fuelIndex'])->name('equipment.fuel');
    Route::post('/equipment/fuel', [EquipmentController::class, 'fuel'])->name('equipment.fuel.store');

    Route::post('/valuations/{id}/certify', [ValuationController::class, 'certify'])->name('valuations.certify');

    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/preview/{slug}', [ReportController::class, 'preview'])->name('reports.preview');
    Route::get('/reports/export/{slug}', [ReportController::class, 'export'])->name('reports.export');
    Route::get('/reports/schedules', [ReportController::class, 'schedules'])->name('reports.schedules');
    Route::post('/reports/schedules', [ReportController::class, 'createSchedule'])->name('reports.schedules.store');

    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread-count');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');

    Route::get('/settings/ui', [SettingsController::class, 'ui'])->name('settings.ui');
    Route::get('/admin/settings', fn () => redirect()->route('settings.ui'))->name('admin.settings');
    Route::post('/admin/settings/ui', [AdminController::class, 'updateUI'])->name('admin.settings.ui');
    Route::get('/admin/users', [AdminController::class, 'users'])->name('admin.users');
    Route::post('/admin/users', [AdminController::class, 'createUser'])->name('admin.users.store');
    Route::patch('/admin/users/{id}', [AdminController::class, 'updateUser'])->name('admin.users.update');
    Route::delete('/admin/users/{id}', [AdminController::class, 'deleteUser'])->name('admin.users.destroy');
    Route::get('/admin/staff', [AdminController::class, 'staff'])->name('admin.staff');
    Route::post('/admin/staff', [AdminController::class, 'storeStaff'])->name('admin.staff.store');
    Route::patch('/admin/staff/{id}', [AdminController::class, 'updateStaff'])->name('admin.staff.update');
    Route::delete('/admin/staff/{id}', [AdminController::class, 'deleteStaff'])->name('admin.staff.destroy');
    Route::get('/admin/menu', [AdminController::class, 'menu'])->name('admin.menu');
    Route::post('/admin/menu', [AdminController::class, 'updateMenu'])->name('admin.menu.store');
    Route::get('/admin/permissions', [AdminController::class, 'permissions'])->name('admin.permissions');
    Route::patch('/admin/permissions/roles/{role}', [AdminController::class, 'updateRolePermissions'])->name('admin.permissions.role.update');
    Route::post('/admin/permissions/sync', [AdminController::class, 'syncPermissions'])->name('admin.permissions.sync');
});
