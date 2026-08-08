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
use App\Http\Controllers\ComplianceRuleController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\HubController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MoneyAccountController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\PositionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectComplianceController;
use App\Http\Controllers\ProjectPhaseController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\RecipientAttendanceController;
use App\Http\Controllers\RecipientController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequisitionCategoryController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\SaleController;
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
    Route::post('/projects/{id}/recipients', [ProjectController::class, 'syncRecipients'])->name('projects.recipients.sync');
    Route::delete('/projects/{id}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    Route::get('/projects/compliance-rules', [ComplianceRuleController::class, 'index'])->name('compliance-rules.index');
    Route::post('/projects/compliance-rules', [ComplianceRuleController::class, 'store'])->name('compliance-rules.store');
    Route::put('/projects/compliance-rules/{id}', [ComplianceRuleController::class, 'update'])->name('compliance-rules.update');
    Route::delete('/projects/compliance-rules/{id}', [ComplianceRuleController::class, 'destroy'])->name('compliance-rules.destroy');

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
        Route::get('/projects/{id}/valuations/{valuationId}', [ValuationController::class, 'show'])->name('projects.valuations.show');
        Route::get('/projects/{id}/valuations/{valuationId}/edit', [ValuationController::class, 'edit'])->name('projects.valuations.edit');
        Route::put('/projects/{id}/valuations/{valuationId}', [ValuationController::class, 'update'])->name('projects.valuations.update');
        Route::delete('/projects/{id}/valuations/{valuationId}', [ValuationController::class, 'destroy'])->name('projects.valuations.destroy');
        Route::get('/projects/{id}/phases/{phaseId}', [ProjectPhaseController::class, 'show'])->name('projects.phases.show');
        Route::post('/projects/{id}/phases', [ProjectPhaseController::class, 'store'])->name('projects.phases.store');
        Route::post('/projects/{id}/phases/{phaseId}/close', [ProjectPhaseController::class, 'close'])->name('projects.phases.close');
        Route::post('/projects/{id}/phases/{phaseId}/retention/release', [ProjectPhaseController::class, 'releaseRetention'])->name('projects.phases.retention.release');
        Route::post('/projects/{id}/phases/{phaseId}/retention/forfeit', [ProjectPhaseController::class, 'forfeitRetention'])->name('projects.phases.retention.forfeit');
        Route::post('/projects/{id}/compliance', [ProjectComplianceController::class, 'store'])->name('projects.compliance.store');
        Route::delete('/projects/{id}/compliance/{itemId}', [ProjectComplianceController::class, 'destroy'])->name('projects.compliance.destroy');
    });

    Route::post('/boq/revisions', [BOQRevisionController::class, 'store'])->name('boq.revisions.store');
    Route::post('/boq/revisions/{id}/activate', [BOQRevisionController::class, 'activate'])->name('boq.revisions.activate');

    Route::get('/requisitions', [RequisitionController::class, 'index'])->name('requisitions.index');
    Route::get('/requisitions/export', [RequisitionController::class, 'export'])->name('requisitions.export');
    Route::get('/requisitions/categories', [RequisitionCategoryController::class, 'index'])->name('requisitions.categories.index');
    Route::post('/requisitions/categories', [RequisitionCategoryController::class, 'store'])->name('requisitions.categories.store');
    Route::put('/requisitions/categories/{id}', [RequisitionCategoryController::class, 'update'])->name('requisitions.categories.update');
    Route::delete('/requisitions/categories/{id}', [RequisitionCategoryController::class, 'destroy'])->name('requisitions.categories.destroy');
    Route::get('/requisitions/departments', [DepartmentController::class, 'index'])->name('requisitions.departments.index');
    Route::post('/requisitions/departments', [DepartmentController::class, 'store'])->name('requisitions.departments.store');
    Route::put('/requisitions/departments/{id}', [DepartmentController::class, 'update'])->name('requisitions.departments.update');
    Route::delete('/requisitions/departments/{id}', [DepartmentController::class, 'destroy'])->name('requisitions.departments.destroy');
    Route::get('/requisitions/positions', [PositionController::class, 'index'])->name('requisitions.positions.index');
    Route::post('/requisitions/positions', [PositionController::class, 'store'])->name('requisitions.positions.store');
    Route::put('/requisitions/positions/{id}', [PositionController::class, 'update'])->name('requisitions.positions.update');
    Route::delete('/requisitions/positions/{id}', [PositionController::class, 'destroy'])->name('requisitions.positions.destroy');
    Route::get('/recipients', [RecipientController::class, 'index'])->name('recipients.index');
    Route::post('/recipients', [RecipientController::class, 'store'])->name('recipients.store');
    Route::put('/recipients/{id}', [RecipientController::class, 'update'])->name('recipients.update');
    Route::delete('/recipients/{id}', [RecipientController::class, 'destroy'])->name('recipients.destroy');
    Route::get('/recipients/attendance/export', [RecipientAttendanceController::class, 'export'])->name('recipients.attendance.export');
    Route::get('/recipients/attendance', [RecipientAttendanceController::class, 'index'])->name('recipients.attendance.index');
    Route::post('/recipients/attendance', [RecipientAttendanceController::class, 'store'])->name('recipients.attendance.store');
    Route::delete('/recipients/attendance/{id}', [RecipientAttendanceController::class, 'destroy'])->name('recipients.attendance.destroy');
    Route::get('/requisitions/create', [RequisitionController::class, 'create'])->name('requisitions.create');
    Route::post('/requisitions', [RequisitionController::class, 'store'])->name('requisitions.store');
    Route::get('/requisitions/review-queue', [RequisitionController::class, 'reviewQueue'])->name('requisitions.review-queue');
    Route::get('/requisitions/fulfill-queue', [RequisitionController::class, 'fulfillQueue'])->name('requisitions.fulfill-queue');
    Route::get('/requisitions/fulfilled', [RequisitionController::class, 'fulfilledList'])->name('requisitions.fulfilled');
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
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{id}', [SaleController::class, 'show'])->name('sales.show');
    Route::post('/sales/{id}/convert-receivable', [SaleController::class, 'convert'])->name('sales.convert');
    Route::post('/sales/{id}/collect', [SaleController::class, 'collect'])->name('sales.collect');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::post('/invoices/{id}/issue', [InvoiceController::class, 'issue'])->whereNumber('id')->name('invoices.issue');
    Route::post('/invoices/{id}/payments', [InvoiceController::class, 'recordPayment'])->whereNumber('id')->name('invoices.payments.store');
    Route::post('/invoices/{id}/signatures', [InvoiceController::class, 'storeSignature'])->whereNumber('id')->name('invoices.signatures.store');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->whereNumber('id')->name('invoices.pdf');
    Route::get('/finance/overview', [FinanceController::class, 'overview'])->name('finance.overview');
    Route::get('/finance/accounts', [MoneyAccountController::class, 'index'])->name('finance.accounts');
    Route::post('/finance/accounts', [MoneyAccountController::class, 'store'])->name('finance.accounts.store');
    Route::post('/finance/accounts/{id}/deposit', [MoneyAccountController::class, 'deposit'])->name('finance.accounts.deposit');
    Route::get('/finance/manager-transactions', [MoneyAccountController::class, 'managerTransactions'])->name('finance.manager-transactions');
    Route::get('/finance/finance-transactions', [MoneyAccountController::class, 'financeTransactions'])->name('finance.finance-transactions');
    Route::get('/finance/approvals', [CashController::class, 'fundApprovals'])->name('finance.approvals');
    Route::get('/finance/approvals/export', [CashController::class, 'exportFundApprovals'])->name('finance.approvals.export');
    Route::get('/finance/organization-cash', [CashController::class, 'organizationCash'])->name('finance.organization-cash');
    Route::post('/finance/cash-requests', [CashController::class, 'request'])->name('finance.cash.request');
    Route::post('/finance/cash-requests/{id}/approve', [CashController::class, 'approve'])->name('finance.cash.approve');
    Route::post('/finance/cash-requests/{id}/reject', [CashController::class, 'reject'])->name('finance.cash.reject');
    Route::post('/finance/cash-requests/{id}/receive', [CashController::class, 'receive'])->name('finance.cash.receive');
    Route::post('/finance/expenses', [ExpenseController::class, 'store'])->name('finance.expenses.store');
    Route::put('/finance/expenses/{id}', [ExpenseController::class, 'update'])->name('finance.expenses.update');
    Route::delete('/finance/expenses/{id}', [ExpenseController::class, 'destroy'])->name('finance.expenses.destroy');
    Route::get('/finance/expenses/export', [ExpenseController::class, 'export'])->name('finance.expenses.export');
    Route::get('/finance/expenses', [ExpenseController::class, 'index'])->name('finance.expenses.index');
    Route::get('/finance/overhead/export', [ExpenseController::class, 'exportOverhead'])->name('finance.overhead.export');
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
    Route::post('/procurement/purchase-orders/{id}/payments', [PurchaseOrderController::class, 'recordPayment'])
        ->whereNumber('id')
        ->name('procurement.purchase-orders.payments.store');
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
