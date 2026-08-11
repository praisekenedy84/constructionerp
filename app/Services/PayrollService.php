<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\BudgetTransactionType;
use App\Enums\ExpenseCategory;
use App\Enums\FulfillmentType;
use App\Enums\PaymentMethod;
use App\Enums\PayrollDeductionType;
use App\Enums\PayrollRunStatus;
use App\Enums\PayStructure;
use App\Enums\RequisitionAddressedTo;
use App\Enums\RequisitionStatus;
use App\Exceptions\InsufficientCashException;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\BudgetTransaction;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use App\Models\User;
use App\Support\OrganizationFundUse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PayrollService
{
    public function __construct(
        private readonly ExpenseService $expenseService,
        private readonly RequisitionService $requisitionService,
        private readonly MoneyAccountService $moneyAccountService,
    ) {}

    /**
     * @return array{
     *     project_id: int,
     *     period_start: string,
     *     period_end: string,
     *     items: array<int, array<string, mixed>>,
     *     total_net_pay: string,
     * }
     */
    public function generatePreview(Project $project, string $periodStart, string $periodEnd): array
    {
        $employees = Employee::assignedToProject($project->id)->get();
        $items = [];
        $totalNetPay = '0';

        foreach ($employees as $employee) {
            $item = $this->calculateEmployeePay($employee, $periodStart, $periodEnd);
            $items[] = $item;
            $totalNetPay = bcadd($totalNetPay, $item['net_pay'], 2);
        }

        return [
            'project_id' => $project->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'items' => $items,
            'total_net_pay' => $totalNetPay,
        ];
    }

    /**
     * Submit a draft payroll run for payment via an administrative cash requisition.
     * Salaries hit overhead only when that requisition is fulfilled.
     */
    public function submitForPayment(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = PayrollRun::lockForUpdate()
                ->with(['items.employee', 'project:id,code,name'])
                ->findOrFail($run->id);

            if ($run->status === PayrollRunStatus::Posted) {
                throw new \InvalidArgumentException('Payroll run has already been posted.');
            }

            if ($run->status === PayrollRunStatus::Approved && $run->requisition_id) {
                throw new \InvalidArgumentException('Payroll run already has a payment requisition.');
            }

            if ($run->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'payroll' => 'Payroll run has no employees to pay.',
                ]);
            }

            $totalNetPay = '0';
            foreach ($run->items as $item) {
                $totalNetPay = bcadd($totalNetPay, (string) $item->net_pay, 2);
            }

            if (bccomp($totalNetPay, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'payroll' => 'Total net pay must be greater than zero.',
                ]);
            }

            $available = $this->moneyAccountService->financeBalance();
            if (bccomp($available, $totalNetPay, 2) < 0) {
                throw new InsufficientCashException(
                    $totalNetPay,
                    $available,
                    'Fund the Finance Wallet before submitting payroll for payment.',
                    'Finance Wallet',
                );
            }

            $category = $this->ensureSalariesCategory();
            $department = $this->ensurePayrollDepartment();
            $periodStart = $run->period_start?->toDateString() ?? (string) $run->period_start;
            $periodEnd = $run->period_end?->toDateString() ?? (string) $run->period_end;
            $projectLabel = $run->project
                ? "{$run->project->code} — {$run->project->name}"
                : "project #{$run->project_id}";

            $items = [];
            $recipients = [];

            foreach ($run->items as $item) {
                $employee = $item->employee;
                $name = $employee?->name ?? 'Employee';
                $netPay = bcadd((string) $item->net_pay, '0', 2);

                if (bccomp($netPay, '0', 2) <= 0) {
                    continue;
                }

                $items[] = [
                    'requisition_category_id' => $category->id,
                    'recipient_name' => $name,
                    'description' => "Salary — {$name} ({$periodStart} to {$periodEnd})",
                    'unit' => 'person',
                    'quantity' => '1',
                    'unit_cost' => $netPay,
                    'details' => [
                        'payroll_run_id' => $run->id,
                        'payroll_item_id' => $item->id,
                        'employee_id' => $item->employee_id,
                    ],
                ];

                $recipients[] = [
                    'name' => $name,
                    'position_name' => $employee?->role,
                ];
            }

            if ($items === []) {
                throw ValidationException::withMessages([
                    'payroll' => 'No payable employees on this run.',
                ]);
            }

            $requisition = $this->requisitionService->create([
                'project_id' => null,
                'department' => $department->name,
                'department_id' => $department->id,
                'requisition_category_id' => $category->id,
                'requisition_category_ids' => [$category->id],
                'requestor_id' => $actor->id,
                'fulfillment_type' => FulfillmentType::CashDisbursement,
                'addressed_to' => RequisitionAddressedTo::Finance,
                'items' => $items,
                'recipients' => $recipients,
            ]);

            $requisition = $this->requisitionService->transition(
                $requisition,
                RequisitionStatus::UnderReview->value,
                $actor,
                ['comment' => "Payroll run #{$run->id} ({$projectLabel})"],
            );

            $run->update([
                'status' => PayrollRunStatus::Approved,
                'requisition_id' => $requisition->id,
            ]);

            return $run->fresh(['items', 'requisition']);
        });
    }

    /**
     * Legacy alias: posting now routes through an administrative requisition.
     */
    public function post(PayrollRun $run, User $actor): PayrollRun
    {
        return $this->submitForPayment($run, $actor);
    }

    /**
     * Called when the linked administrative requisition is fulfilled.
     * Cash / expense already recorded by requisition fulfillment.
     */
    public function markPostedFromRequisition(PayrollRun $run): void
    {
        $run = PayrollRun::lockForUpdate()->with('items')->findOrFail($run->id);

        if ($run->status === PayrollRunStatus::Posted) {
            $this->removeLegacyPayrollBudgetTransactions($run);

            return;
        }

        $this->removeLegacyPayrollBudgetTransactions($run);
        $run->update(['status' => PayrollRunStatus::Posted]);
    }

    /**
     * After an administrative Salaries requisition is fulfilled without a prior
     * payroll run, create posted run(s) so payroll module + reports show who was paid.
     *
     * @return list<PayrollRun>
     */
    public function recordRunsFromFulfilledRequisition(Requisition $requisition, User $actor): array
    {
        $requisition->loadMissing(['items', 'payrollRun']);

        if ($requisition->payrollRun) {
            $this->markPostedFromRequisition($requisition->payrollRun);

            return [$requisition->payrollRun->fresh(['items'])];
        }

        if (! $this->isSalariesRequisition($requisition)) {
            return [];
        }

        $byProject = [];

        foreach ($requisition->items as $item) {
            $employeeId = (int) ($item->details['employee_id'] ?? 0);
            if ($employeeId <= 0) {
                continue;
            }

            $employee = Employee::query()->find($employeeId);
            if (! $employee) {
                continue;
            }

            $netPay = $item->amountForQuantity((string) $item->quantity);
            if (bccomp($netPay, '0', 2) <= 0) {
                continue;
            }

            $projectId = (int) $employee->project_id;
            $byProject[$projectId][] = [
                'employee_id' => $employee->id,
                'net_pay' => $netPay,
            ];
        }

        if ($byProject === []) {
            return [];
        }

        $runs = [];
        $periodEnd = now()->toDateString();
        $periodStart = now()->startOfMonth()->toDateString();

        foreach ($byProject as $projectId => $lines) {
            $run = PayrollRun::create([
                'project_id' => $projectId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => PayrollRunStatus::Posted,
                'requisition_id' => $requisition->id,
            ]);

            foreach ($lines as $line) {
                PayrollItem::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $line['employee_id'],
                    'base' => $line['net_pay'],
                    'overtime' => '0',
                    'allowances' => '0',
                    'deductions_total' => '0',
                    'net_pay' => $line['net_pay'],
                    'created_at' => now(),
                ]);
            }

            $runs[] = $run->load('items');
        }

        return $runs;
    }

    public function isSalariesRequisition(Requisition $requisition): bool
    {
        $label = mb_strtolower($requisition->categoryLabel());

        return str_contains($label, mb_strtolower(OrganizationFundUse::SALARIES))
            || str_contains($label, 'payroll');
    }

    public function ensureSalariesCategory(): RequisitionCategory
    {
        $existing = RequisitionCategory::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower(OrganizationFundUse::SALARIES)])
            ->first();

        if ($existing) {
            $updates = [];
            if (! $existing->is_active) {
                $updates['is_active'] = true;
            }
            if ($existing->expense_type !== ExpenseCategory::Indirect) {
                $updates['expense_type'] = ExpenseCategory::Indirect;
            }
            if ($updates !== []) {
                $existing->update($updates);
            }

            return $existing;
        }

        return RequisitionCategory::create([
            'name' => OrganizationFundUse::SALARIES,
            'description' => 'Staff payroll / salaries (administrative overhead)',
            'expense_type' => ExpenseCategory::Indirect,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    public function ensurePayrollDepartment(): Department
    {
        $existing = Department::query()
            ->whereRaw('LOWER(name) = ?', ['payroll'])
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing;
        }

        return Department::create([
            'name' => 'Payroll',
            'description' => 'Staff salary payments',
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    /**
     * Migrate posted runs that still only have PAYROLL budget txs (pre-overhead change)
     * into Salaries overhead expenses, and drop those budget txs.
     */
    public function backfillLegacyPayrollOverhead(User $actor): int
    {
        $created = 0;

        $runs = PayrollRun::query()
            ->where('status', PayrollRunStatus::Posted)
            ->with(['items', 'project:id,code,name'])
            ->get();

        foreach ($runs as $run) {
            $ref = "payroll_run:{$run->id}";
            $exists = Expense::query()
                ->where('category', ExpenseCategory::Indirect)
                ->where('activity_ref', $ref)
                ->exists();

            if ($exists) {
                $this->removeLegacyPayrollBudgetTransactions($run);

                continue;
            }

            if ($run->requisition_id) {
                $reqExpense = Expense::query()
                    ->where('requisition_id', $run->requisition_id)
                    ->where('category', ExpenseCategory::Indirect)
                    ->first();

                if ($reqExpense) {
                    $reqExpense->update([
                        'sub_type' => OrganizationFundUse::SALARIES,
                        'activity_ref' => $ref,
                    ]);
                    $this->removeLegacyPayrollBudgetTransactions($run);
                    $created++;

                    continue;
                }
            }

            DB::transaction(function () use ($run, $actor, &$created) {
                if ($this->recordSalariesOverheadExpense($run, $actor, consumeCash: false)) {
                    $created++;
                }
                $this->removeLegacyPayrollBudgetTransactions($run);
            });
        }

        return $created;
    }

    /**
     * @return bool True when an overhead expense row was created.
     */
    private function recordSalariesOverheadExpense(PayrollRun $run, User $actor, bool $consumeCash = true): bool
    {
        $totalNetPay = '0';
        foreach ($run->items as $item) {
            $totalNetPay = bcadd($totalNetPay, (string) $item->net_pay, 2);
        }

        if (bccomp($totalNetPay, '0', 2) <= 0) {
            return false;
        }

        $ref = "payroll_run:{$run->id}";
        if (Expense::query()->where('activity_ref', $ref)->exists()) {
            return false;
        }

        $periodStart = $run->period_start?->toDateString() ?? (string) $run->period_start;
        $periodEnd = $run->period_end?->toDateString() ?? (string) $run->period_end;
        $projectLabel = $run->project
            ? "{$run->project->code} — {$run->project->name}"
            : "project #{$run->project_id}";

        $payload = [
            'category' => ExpenseCategory::Indirect,
            'sub_type' => OrganizationFundUse::SALARIES,
            'amount' => $totalNetPay,
            'description' => "Payroll run #{$run->id} ({$projectLabel}, {$periodStart} to {$periodEnd})",
            'expense_date' => $periodEnd,
            'activity_ref' => $ref,
            'recorded_by' => $actor->id,
        ];

        if ($consumeCash) {
            $payload['method'] = PaymentMethod::Bank->value;
            $payload['payee'] = 'Payroll';
            $payload['reference_no'] = "PAYROLL-{$run->id}";
        }

        $this->expenseService->create($payload);

        return true;
    }

    private function removeLegacyPayrollBudgetTransactions(PayrollRun $run): void
    {
        $itemIds = $run->items->pluck('id')->filter()->all();
        if ($itemIds === []) {
            return;
        }

        BudgetTransaction::query()
            ->where('type', BudgetTransactionType::Payroll)
            ->where('reference_entity_type', 'payroll_item')
            ->whereIn('reference_entity_id', $itemIds)
            ->delete();
    }

    public function createRunFromPreview(array $preview): PayrollRun
    {
        return DB::transaction(function () use ($preview) {
            $run = PayrollRun::create([
                'project_id' => $preview['project_id'],
                'period_start' => $preview['period_start'],
                'period_end' => $preview['period_end'],
                'status' => PayrollRunStatus::Draft,
            ]);

            foreach ($preview['items'] as $itemData) {
                $item = PayrollItem::create([
                    'payroll_run_id' => $run->id,
                    'employee_id' => $itemData['employee_id'],
                    'boq_item_id' => $itemData['boq_item_id'] ?? null,
                    'base' => $itemData['base'],
                    'overtime' => $itemData['overtime'] ?? '0',
                    'allowances' => $itemData['allowances'] ?? '0',
                    'deductions_total' => $itemData['deductions_total'],
                    'net_pay' => $itemData['net_pay'],
                    'created_at' => now(),
                ]);

                foreach ($itemData['deductions'] ?? [] as $deduction) {
                    PayrollDeduction::create([
                        'payroll_item_id' => $item->id,
                        'type' => $deduction['type'],
                        'amount' => $deduction['amount'],
                        'created_at' => now(),
                    ]);
                }

                foreach ($itemData['advance_recoveries'] ?? [] as $advanceId) {
                    Advance::where('id', $advanceId)
                        ->whereNull('recovered_at')
                        ->update([
                            'recovered_at' => now(),
                            'payroll_item_id' => $item->id,
                        ]);
                }
            }

            return $run->load('items');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function calculateEmployeePay(Employee $employee, string $periodStart, string $periodEnd): array
    {
        $attendances = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        $base = '0';

        if ($employee->pay_structure === PayStructure::Daily) {
            foreach ($attendances as $attendance) {
                $base = bcadd($base, $this->dailyPayForAttendance($employee, $attendance), 2);
            }
        } else {
            $daysInPeriod = (int) (strtotime($periodEnd) - strtotime($periodStart)) / 86400 + 1;
            $workedDays = $attendances->filter(fn ($a) => $a->status !== AttendanceStatus::Absent
                && $a->status !== AttendanceStatus::Leave)->count();
            $monthlySalary = bcadd((string) ($employee->monthly_salary ?? '0'), '0', 2);

            if ($daysInPeriod > 0 && $workedDays > 0) {
                $base = bcmul(
                    bcdiv($monthlySalary, (string) $daysInPeriod, 4),
                    (string) $workedDays,
                    2
                );
            }
        }

        $overtime = '0';
        $allowances = '0';
        $deductions = [];
        $deductionsTotal = '0';

        $unrecoveredAdvances = Advance::where('employee_id', $employee->id)
            ->whereNull('recovered_at')
            ->get();

        $advanceRecoveries = [];

        foreach ($unrecoveredAdvances as $advance) {
            $amount = bcadd((string) $advance->amount, '0', 2);
            $deductions[] = [
                'type' => PayrollDeductionType::AdvanceRecovery,
                'amount' => $amount,
            ];
            $deductionsTotal = bcadd($deductionsTotal, $amount, 2);
            $advanceRecoveries[] = $advance->id;
        }

        $gross = bcadd(bcadd($base, $overtime, 2), $allowances, 2);
        $netPay = bcsub($gross, $deductionsTotal, 2);

        if (bccomp($netPay, '0', 2) < 0) {
            $netPay = '0';
        }

        return [
            'employee_id' => $employee->id,
            'employee_no' => $employee->employee_no,
            'employee_name' => $employee->name,
            'boq_item_id' => null,
            'base' => $base,
            'overtime' => $overtime,
            'allowances' => $allowances,
            'deductions' => $deductions,
            'deductions_total' => $deductionsTotal,
            'net_pay' => $netPay,
            'advance_recoveries' => $advanceRecoveries,
        ];
    }

    private function dailyPayForAttendance(Employee $employee, Attendance $attendance): string
    {
        $dailyRate = bcadd((string) ($employee->daily_rate ?? '0'), '0', 2);

        return match ($attendance->status) {
            AttendanceStatus::Present => $dailyRate,
            AttendanceStatus::HalfDay => bcdiv($dailyRate, '2', 2),
            default => '0',
        };
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, PayrollRun>
     */
    public function listRuns(Project $project): \Illuminate\Database\Eloquent\Collection
    {
        return PayrollRun::query()
            ->where('project_id', $project->id)
            ->orderByDesc('period_end')
            ->limit(10)
            ->get();
    }

    public function generate(array $data, User $user): PayrollRun
    {
        $project = Project::findOrFail($data['project_id']);
        $preview = $this->generatePreview(
            $project,
            $data['period_start'],
            $data['period_end'],
        );

        if (! empty($data['overrides'])) {
            $preview = $this->applyManualOverrides($preview, $data['overrides']);
        }

        return $this->createRunFromPreview($preview);
    }

    /**
     * Replace calculated pay with a direct net amount (skips attendance-based base).
     *
     * @param  array{
     *     project_id: int,
     *     period_start: string,
     *     period_end: string,
     *     items: array<int, array<string, mixed>>,
     *     total_net_pay: string,
     * }  $preview
     * @param  list<array{employee_id: int|string, net_pay: int|string|float}>  $overrides
     * @return array{
     *     project_id: int,
     *     period_start: string,
     *     period_end: string,
     *     items: array<int, array<string, mixed>>,
     *     total_net_pay: string,
     * }
     */
    public function applyManualOverrides(array $preview, array $overrides): array
    {
        $byEmployee = [];
        foreach ($overrides as $override) {
            $byEmployee[(int) $override['employee_id']] = bcadd((string) $override['net_pay'], '0', 2);
        }

        $totalNetPay = '0';

        foreach ($preview['items'] as $index => $item) {
            $employeeId = (int) $item['employee_id'];

            if (array_key_exists($employeeId, $byEmployee)) {
                $amount = $byEmployee[$employeeId];
                if (bccomp($amount, '0', 2) < 0) {
                    $amount = '0';
                }

                $preview['items'][$index] = array_merge($item, [
                    'base' => $amount,
                    'overtime' => '0',
                    'allowances' => '0',
                    'deductions' => [],
                    'deductions_total' => '0',
                    'net_pay' => $amount,
                    'advance_recoveries' => [],
                    'manual_override' => true,
                ]);
            }

            $totalNetPay = bcadd($totalNetPay, (string) $preview['items'][$index]['net_pay'], 2);
        }

        $preview['total_net_pay'] = $totalNetPay;

        return $preview;
    }

    /**
     * @return array{
     *     employees: \Illuminate\Database\Eloquent\Collection<int, Employee>,
     *     attendances: \Illuminate\Database\Eloquent\Collection<int, Attendance>,
     *     date: string,
     * }
     */
    public function attendanceGrid(array $filters = []): array
    {
        $date = $filters['date'] ?? now()->toDateString();

        $employees = Employee::query()
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->assignedToProject((int) $id))
            ->orderBy('name')
            ->get();

        $attendances = Attendance::query()
            ->whereDate('date', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get();

        return [
            'employees' => $employees,
            'attendances' => $attendances,
            'date' => $date,
        ];
    }

    public function recordAttendance(array $entries, User $actor): void
    {
        DB::transaction(function () use ($entries) {
            foreach ($entries as $entry) {
                Attendance::updateOrCreate(
                    [
                        'employee_id' => $entry['employee_id'],
                        'date' => $entry['date'] ?? now()->toDateString(),
                    ],
                    [
                        'status' => $entry['status'],
                        'hours_worked' => $entry['hours_worked'] ?? null,
                    ],
                );
            }
        });
    }
}
