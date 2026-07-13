<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\BudgetTransactionType;
use App\Enums\PayrollDeductionType;
use App\Enums\PayrollRunStatus;
use App\Enums\PayStructure;
use App\Models\Advance;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\PayrollDeduction;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PayrollService
{
    public function __construct(
        private readonly BudgetService $budgetService,
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
        $employees = Employee::where('project_id', $project->id)->get();
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

    public function post(PayrollRun $run, User $actor): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = PayrollRun::lockForUpdate()->with('items')->findOrFail($run->id);

            if ($run->status === PayrollRunStatus::Posted) {
                throw new \InvalidArgumentException('Payroll run has already been posted.');
            }

            foreach ($run->items as $item) {
                $this->budgetService->createTransaction($run->project_id, [
                    'type' => BudgetTransactionType::Payroll,
                    'amount' => (string) $item->net_pay,
                    'boq_item_id' => $item->boq_item_id,
                    'reference_entity_type' => 'payroll_item',
                    'reference_entity_id' => $item->id,
                    'created_by' => $actor->id,
                ]);
            }

            $run->update(['status' => PayrollRunStatus::Posted]);

            return $run->fresh(['items']);
        });
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

        return $this->createRunFromPreview($preview);
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
            ->when($filters['project_id'] ?? null, fn ($q, $id) => $q->where('project_id', $id))
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
