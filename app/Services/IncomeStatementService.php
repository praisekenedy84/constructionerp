<?php

namespace App\Services;

use App\Enums\AccountTransactionType;
use App\Enums\BudgetTransactionType;
use App\Enums\DepositSource;
use App\Enums\ExpenseCategory;
use App\Enums\SaleStatus;
use App\Models\AccountTransaction;
use App\Models\BudgetTransaction;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Sale;
use App\Support\OrganizationFundUse;
use Carbon\Carbon;

class IncomeStatementService
{
    /**
     * @param  array{
     *     from?: string|null,
     *     to?: string|null,
     *     project_id?: int|string|null,
     *     memo_no?: string|null,
     * }  $filters
     * @param  array{
     *     interest?: string|float|int|null,
     *     interest_mode?: string|null,
     *     depreciation?: string|float|int|null,
     *     depreciation_mode?: string|null,
     *     corporate_tax?: string|float|int|null,
     *     corporate_tax_mode?: string|null,
     *     adhoc?: list<array{
     *         label?: string,
     *         amount?: string|float|int,
     *         value?: string|float|int,
     *         mode?: string,
     *         section?: string
     *     }>|null,
     * }  $adjustments
     * @return array{
     *     memo_no: string|null,
     *     period: array{from: string|null, to: string|null, project_id: int|null},
     *     lines: list<array{
     *         key: string,
     *         section: string,
     *         label: string,
     *         amount: string,
     *         is_total: bool,
     *         is_system: bool,
     *         is_input: bool
     *     }>,
     *     totals: array{
     *         total_revenue: string,
     *         total_direct: string,
     *         total_indirect: string,
     *         total_expenses: string,
     *         ebitda: string,
     *         interest: string,
     *         depreciation: string,
     *         interest_depreciation: string,
     *         corporate_tax: string,
     *         loss_recognition: string,
     *         net_profit: string
     *     },
     *     bases: array{
     *         revenue: string,
     *         direct: string,
     *         indirect: string,
     *         ebitda: string
     *     },
     *     adjustments: array{
     *         interest: string,
     *         interest_mode: string,
     *         interest_value: string,
     *         depreciation: string,
     *         depreciation_mode: string,
     *         depreciation_value: string,
     *         corporate_tax: string,
     *         corporate_tax_mode: string,
     *         corporate_tax_value: string,
     *         adhoc: list<array{label: string, amount: string, value: string, mode: string, section: string}>
     *     },
     *     projects: list<array{id: int, code: string, name: string}>
     * }
     */
    public function build(array $filters = [], array $adjustments = []): array
    {
        $from = $this->parseDate($filters['from'] ?? null)?->startOfDay();
        $to = $this->parseDate($filters['to'] ?? null)?->endOfDay();
        $projectId = isset($filters['project_id']) && $filters['project_id'] !== '' && $filters['project_id'] !== null
            ? (int) $filters['project_id']
            : null;

        $revenueDetails = $this->revenueLines($from, $to, $projectId);
        $directDetails = $this->directExpenseLines($from, $to, $projectId);
        $indirectDetails = $this->indirectExpenseLines($from, $to, $projectId);
        $lossRecognition = $this->lossRecognition($from, $to, $projectId);

        $systemRevenue = $this->sumAmounts($revenueDetails);
        $systemDirect = $this->sumAmounts($directDetails);
        $systemIndirect = $this->sumAmounts($indirectDetails);
        $systemEbitda = bcsub($systemRevenue, bcadd($systemDirect, $systemIndirect, 2), 2);

        $bases = [
            'revenue' => $systemRevenue,
            'direct' => $systemDirect,
            'indirect' => $systemIndirect,
            'ebitda' => $systemEbitda,
        ];

        $adhoc = $this->resolveAdhoc($adjustments['adhoc'] ?? [], $bases);

        foreach ($adhoc as $row) {
            $entry = [
                'key' => 'adhoc_'.md5($row['section'].'|'.$row['label']),
                'section' => $row['section'],
                'label' => $this->labeledAmount($row['label'], $row['mode'], $row['value']),
                'amount' => $row['amount'],
                'is_total' => false,
                'is_system' => false,
                'is_input' => true,
            ];

            match ($row['section']) {
                'revenue' => $revenueDetails[] = $entry,
                'direct' => $directDetails[] = $entry,
                'indirect' => $indirectDetails[] = $entry,
                default => null,
            };
        }

        $totalRevenue = $this->sumAmounts($revenueDetails);
        $totalDirect = $this->sumAmounts($directDetails);
        $totalIndirect = $this->sumAmounts($indirectDetails);
        $totalExpenses = bcadd($totalDirect, $totalIndirect, 2);
        $ebitda = bcsub($totalRevenue, $totalExpenses, 2);

        $interestMode = $this->mode($adjustments['interest_mode'] ?? 'fixed');
        $interestValue = $this->money($adjustments['interest'] ?? '0');
        $interest = $this->resolveAmount($interestMode, $interestValue, $ebitda);

        $depreciationMode = $this->mode($adjustments['depreciation_mode'] ?? 'fixed');
        $depreciationValue = $this->money($adjustments['depreciation'] ?? '0');
        $depreciation = $this->resolveAmount($depreciationMode, $depreciationValue, $ebitda);

        $belowAdhoc = array_values(array_filter(
            $adhoc,
            fn (array $row) => $row['section'] === 'below_ebitda',
        ));
        // Re-resolve below-EBITDA adhoc against final EBITDA (section adhoc already applied).
        $belowAdhoc = array_map(function (array $row) use ($ebitda) {
            if ($row['mode'] === 'percent') {
                $row['amount'] = $this->resolveAmount('percent', $row['value'], $ebitda);
            }

            return $row;
        }, $belowAdhoc);

        $belowAdhocTotal = '0.00';
        foreach ($belowAdhoc as $row) {
            $belowAdhocTotal = bcadd($belowAdhocTotal, $row['amount'], 2);
        }

        $interestDepreciation = bcadd($interest, $depreciation, 2);
        $interestDepreciation = bcadd($interestDepreciation, $belowAdhocTotal, 2);

        $profitBeforeTax = bcsub($ebitda, $interestDepreciation, 2);
        $corporateTaxMode = $this->mode($adjustments['corporate_tax_mode'] ?? 'fixed');
        $corporateTaxValue = $this->money($adjustments['corporate_tax'] ?? '0');
        $corporateTax = $this->resolveAmount($corporateTaxMode, $corporateTaxValue, $profitBeforeTax);

        $netProfit = bcsub($profitBeforeTax, $corporateTax, 2);
        $netProfit = bcsub($netProfit, $lossRecognition, 2);

        $lines = [];

        $lines[] = $this->headerLine('revenue', 'Total Revenue');
        foreach ($revenueDetails as $line) {
            $lines[] = $line;
        }
        $lines[] = $this->totalLine('total_revenue', 'revenue', 'Total Revenue', $totalRevenue);

        $lines[] = $this->headerLine('direct', 'Less: Direct Expenses (Operational)');
        foreach ($directDetails as $line) {
            $lines[] = $line;
        }
        $lines[] = $this->totalLine('total_direct', 'direct', 'Total Direct Expenses', $totalDirect);

        $lines[] = $this->headerLine('indirect', 'Less: Indirect Expenses (Administrative Expenses)');
        foreach ($indirectDetails as $line) {
            $lines[] = $line;
        }
        $lines[] = $this->totalLine('total_indirect', 'indirect', 'Total Indirect Expenses (Administrative Expenses)', $totalIndirect);

        $lines[] = $this->totalLine('total_expenses', 'summary', 'Total Expenses (Direct + Indirect)', $totalExpenses);
        $lines[] = $this->totalLine('ebitda', 'summary', 'Profit Before Tax (EBITDA)', $ebitda);

        $lines[] = $this->headerLine('below_ebitda', 'Less:');
        $lines[] = [
            'key' => 'interest',
            'section' => 'below_ebitda',
            'label' => $this->labeledAmount('Interest (Bank/Corporate)', $interestMode, $interestValue),
            'amount' => $interest,
            'is_total' => false,
            'is_system' => false,
            'is_input' => true,
        ];
        $lines[] = [
            'key' => 'depreciation',
            'section' => 'below_ebitda',
            'label' => $this->labeledAmount('Depreciation', $depreciationMode, $depreciationValue),
            'amount' => $depreciation,
            'is_total' => false,
            'is_system' => false,
            'is_input' => true,
        ];
        foreach ($belowAdhoc as $row) {
            $lines[] = [
                'key' => 'adhoc_'.md5('below_ebitda|'.$row['label']),
                'section' => 'below_ebitda',
                'label' => $this->labeledAmount($row['label'], $row['mode'], $row['value']),
                'amount' => $row['amount'],
                'is_total' => false,
                'is_system' => false,
                'is_input' => true,
            ];
        }
        $lines[] = $this->totalLine(
            'interest_depreciation',
            'below_ebitda',
            'Total Interest & Depreciation',
            $interestDepreciation,
        );
        $lines[] = [
            'key' => 'corporate_tax',
            'section' => 'below_ebitda',
            'label' => $this->labeledAmount('Corporate Tax', $corporateTaxMode, $corporateTaxValue),
            'amount' => $corporateTax,
            'is_total' => false,
            'is_system' => false,
            'is_input' => true,
        ];
        if (bccomp($lossRecognition, '0', 2) === 1) {
            $lines[] = [
                'key' => 'loss_recognition',
                'section' => 'below_ebitda',
                'label' => 'Loss recognition',
                'amount' => $lossRecognition,
                'is_total' => false,
                'is_system' => true,
                'is_input' => false,
            ];
        }

        $lines[] = $this->totalLine('net_profit', 'summary', 'Net Profit', $netProfit);

        // Keep below-EBITDA adhoc amounts in the returned adjustments list in sync.
        $adhocForReturn = array_map(function (array $row) use ($belowAdhoc) {
            if ($row['section'] !== 'below_ebitda') {
                return $row;
            }
            foreach ($belowAdhoc as $below) {
                if ($below['label'] === $row['label']) {
                    return $below;
                }
            }

            return $row;
        }, $adhoc);

        return [
            'memo_no' => isset($filters['memo_no']) && $filters['memo_no'] !== ''
                ? (string) $filters['memo_no']
                : null,
            'period' => [
                'from' => $from?->toDateString(),
                'to' => $to?->toDateString(),
                'project_id' => $projectId,
            ],
            'lines' => $lines,
            'totals' => [
                'total_revenue' => $totalRevenue,
                'total_direct' => $totalDirect,
                'total_indirect' => $totalIndirect,
                'total_expenses' => $totalExpenses,
                'ebitda' => $ebitda,
                'interest' => $interest,
                'depreciation' => $depreciation,
                'interest_depreciation' => $interestDepreciation,
                'corporate_tax' => $corporateTax,
                'loss_recognition' => $lossRecognition,
                'net_profit' => $netProfit,
            ],
            'bases' => $bases,
            'adjustments' => [
                'interest' => $interest,
                'interest_mode' => $interestMode,
                'interest_value' => $interestValue,
                'depreciation' => $depreciation,
                'depreciation_mode' => $depreciationMode,
                'depreciation_value' => $depreciationValue,
                'corporate_tax' => $corporateTax,
                'corporate_tax_mode' => $corporateTaxMode,
                'corporate_tax_value' => $corporateTaxValue,
                'adhoc' => $adhocForReturn,
            ],
            'projects' => Project::query()
                ->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'name' => $p->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, amount: string}>
     */
    public function exportRows(array $statement): array
    {
        $rows = [];

        foreach ($statement['lines'] as $line) {
            $indent = $line['is_total'] || str_starts_with($line['key'], 'header_')
                ? ''
                : '    ';
            $rows[] = [
                'key' => $line['key'],
                'label' => $indent.$line['label'],
                'amount' => $line['amount'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}>
     */
    private function revenueLines(?Carbon $from, ?Carbon $to, ?int $projectId): array
    {
        $converted = [SaleStatus::Receivable, SaleStatus::PartiallyPaid, SaleStatus::Paid];

        $sales = Sale::query()
            ->whereIn('status', $converted)
            ->whereNotNull('profit_amount')
            ->where('profit_amount', '>', 0)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->where('converted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('converted_at', '<=', $to))
            ->get(['phase_id', 'sale_code', 'profit_amount']);

        $projectProfit = '0.00';
        $retention = '0.00';

        foreach ($sales as $sale) {
            $amount = $this->money($sale->profit_amount);
            if ($sale->phase_id === null || str_starts_with((string) $sale->sale_code, 'RET-')) {
                $retention = bcadd($retention, $amount, 2);
            } else {
                $projectProfit = bcadd($projectProfit, $amount, 2);
            }
        }

        $otherIncome = AccountTransaction::query()
            ->where('type', AccountTransactionType::Deposit)
            ->where('deposit_source', DepositSource::OtherIncome)
            ->when($from, fn ($q) => $q->where('occurred_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('occurred_at', '<=', $to))
            ->sum('amount');
        $otherIncome = $this->money($otherIncome);

        $lines = [];
        if (bccomp($projectProfit, '0', 2) === 1) {
            $lines[] = $this->detailLine('project_receivables', 'revenue', 'Project receivables', $projectProfit);
        }
        if (bccomp($retention, '0', 2) === 1) {
            $lines[] = $this->detailLine('retention_receivables', 'revenue', 'Retention receivables', $retention);
        }
        if (bccomp($otherIncome, '0', 2) === 1) {
            $lines[] = $this->detailLine('other_income', 'revenue', 'Other income', $otherIncome);
        }

        return $lines;
    }

    private function lossRecognition(?Carbon $from, ?Carbon $to, ?int $projectId): string
    {
        $converted = [SaleStatus::Receivable, SaleStatus::PartiallyPaid, SaleStatus::Paid];

        $total = Sale::query()
            ->whereIn('status', $converted)
            ->whereNotNull('profit_amount')
            ->where('profit_amount', '<', 0)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->where('converted_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('converted_at', '<=', $to))
            ->sum('profit_amount');

        if (bccomp((string) $total, '0', 2) >= 0) {
            return '0.00';
        }

        return $this->money(bcmul((string) $total, '-1', 2));
    }

    /**
     * @return list<array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}>
     */
    private function directExpenseLines(?Carbon $from, ?Carbon $to, ?int $projectId): array
    {
        $buckets = [
            'casual_labour' => '0.00',
            'fuel' => '0.00',
            'machine' => '0.00',
        ];
        /** @var array<string, string> $other */
        $other = [];

        $expenses = Expense::query()
            ->where('category', ExpenseCategory::Direct)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to->toDateString()))
            ->get(['sub_type', 'amount', 'valuation_id']);

        foreach ($expenses as $expense) {
            $amount = $this->money($expense->amount);
            $bucket = $this->directBucket((string) ($expense->sub_type ?? ''), $expense->valuation_id !== null);

            if (isset($buckets[$bucket])) {
                $buckets[$bucket] = bcadd($buckets[$bucket], $amount, 2);
            } else {
                $other[$bucket] = bcadd($other[$bucket] ?? '0.00', $amount, 2);
            }
        }

        $fuelBudget = BudgetTransaction::query()
            ->where('type', BudgetTransactionType::FuelCost)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->sum('amount');
        $buckets['fuel'] = bcadd($buckets['fuel'], $this->money($fuelBudget), 2);

        $equipmentBudget = BudgetTransaction::query()
            ->where('type', BudgetTransactionType::EquipmentCost)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to))
            ->sum('amount');
        $buckets['machine'] = bcadd($buckets['machine'], $this->money($equipmentBudget), 2);

        $lines = [];
        $preferred = [
            'casual_labour' => 'Casual Labour',
            'fuel' => 'Fuel',
            'machine' => 'Machine',
        ];

        foreach ($preferred as $key => $label) {
            if (bccomp($buckets[$key], '0', 2) === 1) {
                $lines[] = $this->detailLine($key, 'direct', $label, $buckets[$key]);
            }
        }

        ksort($other);
        foreach ($other as $label => $amount) {
            if (bccomp($amount, '0', 2) === 1) {
                $lines[] = $this->detailLine(
                    'direct_'.md5($label),
                    'direct',
                    $label,
                    $amount,
                );
            }
        }

        return $lines;
    }

    /**
     * @return list<array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}>
     */
    private function indirectExpenseLines(?Carbon $from, ?Carbon $to, ?int $projectId): array
    {
        $salaries = '0.00';
        $tax = '0.00';
        $office = '0.00';

        $expenses = Expense::query()
            ->where('category', ExpenseCategory::Indirect)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->when($from, fn ($q) => $q->whereDate('expense_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('expense_date', '<=', $to->toDateString()))
            ->get(['sub_type', 'amount']);

        foreach ($expenses as $expense) {
            $amount = $this->money($expense->amount);
            $sub = (string) ($expense->sub_type ?? OrganizationFundUse::GENERAL);

            if ($this->matchesAny($sub, ['salary', 'salaries', 'payroll'])) {
                $salaries = bcadd($salaries, $amount, 2);
            } elseif ($this->matchesAny($sub, ['tax', 'wht', 'withholding'])) {
                $tax = bcadd($tax, $amount, 2);
            } else {
                $office = bcadd($office, $amount, 2);
            }
        }

        $lines = [];
        if (bccomp($salaries, '0', 2) === 1) {
            $lines[] = $this->detailLine('salaries', 'indirect', 'Salaries', $salaries);
        }
        if (bccomp($tax, '0', 2) === 1) {
            $lines[] = $this->detailLine('tax', 'indirect', 'Tax', $tax);
        }
        if (bccomp($office, '0', 2) === 1) {
            $lines[] = $this->detailLine('office_expenses', 'indirect', 'Office Expenses', $office);
        }

        return $lines;
    }

    private function directBucket(string $subType, bool $isIpc): string
    {
        $normalized = mb_strtolower(trim($subType));

        if ($this->matchesAny($normalized, ['labour', 'labor', 'casual'])) {
            return 'casual_labour';
        }
        if ($this->matchesAny($normalized, ['fuel'])) {
            return 'fuel';
        }
        if ($this->matchesAny($normalized, ['machine', 'equipment'])) {
            return 'machine';
        }

        if ($normalized === '') {
            return $isIpc ? 'IPC deductions' : 'Other direct';
        }

        // Preserve original casing when possible for dynamic categories / IPC names.
        return trim($subType) !== '' ? trim($subType) : ($isIpc ? 'IPC deductions' : 'Other direct');
    }

    /**
     * @param  list<string>  $needles
     */
    private function matchesAny(string $haystack, array $needles): bool
    {
        $haystack = mb_strtolower($haystack);

        foreach ($needles as $needle) {
            if ($haystack === $needle || str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{label?: string, amount?: string|float|int, value?: string|float|int, mode?: string, section?: string}>  $rows
     * @param  array{revenue: string, direct: string, indirect: string, ebitda: string}  $bases
     * @return list<array{label: string, amount: string, value: string, mode: string, section: string}>
     */
    private function resolveAdhoc(array $rows, array $bases): array
    {
        $allowed = ['revenue', 'direct', 'indirect', 'below_ebitda'];
        $normalized = [];

        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            $section = (string) ($row['section'] ?? '');
            $mode = $this->mode($row['mode'] ?? 'fixed');
            $value = $this->money($row['value'] ?? $row['amount'] ?? '0');

            if ($label === '' || ! in_array($section, $allowed, true)) {
                continue;
            }

            $base = match ($section) {
                'revenue' => $bases['revenue'],
                'direct' => $bases['direct'],
                'indirect' => $bases['indirect'],
                default => $bases['ebitda'],
            };
            $amount = $this->resolveAmount($mode, $value, $base);

            if (bccomp($amount, '0', 2) === 0 && bccomp($value, '0', 2) === 0) {
                continue;
            }

            $normalized[] = [
                'label' => $label,
                'amount' => $amount,
                'value' => $value,
                'mode' => $mode,
                'section' => $section,
            ];
        }

        return $normalized;
    }

    private function resolveAmount(string $mode, string $value, string $base): string
    {
        if ($mode !== 'percent') {
            return $this->money($value);
        }

        // amount = base * rate / 100
        return $this->money(bcdiv(bcmul($base, $value, 4), '100', 4));
    }

    private function mode(mixed $mode): string
    {
        return $mode === 'percent' ? 'percent' : 'fixed';
    }

    private function labeledAmount(string $label, string $mode, string $value): string
    {
        if ($mode !== 'percent' || bccomp($value, '0', 2) === 0) {
            return $label;
        }

        return $label.' @ '.$this->money($value).'%';
    }

    /**
     * @param  list<array{amount: string}>  $lines
     */
    private function sumAmounts(array $lines): string
    {
        $total = '0.00';
        foreach ($lines as $line) {
            $total = bcadd($total, $line['amount'], 2);
        }

        return $total;
    }

    /**
     * @return array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}
     */
    private function detailLine(string $key, string $section, string $label, string $amount): array
    {
        return [
            'key' => $key,
            'section' => $section,
            'label' => $label,
            'amount' => $amount,
            'is_total' => false,
            'is_system' => true,
            'is_input' => false,
        ];
    }

    /**
     * @return array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}
     */
    private function totalLine(string $key, string $section, string $label, string $amount): array
    {
        return [
            'key' => $key,
            'section' => $section,
            'label' => $label,
            'amount' => $amount,
            'is_total' => true,
            'is_system' => true,
            'is_input' => false,
        ];
    }

    /**
     * @return array{key: string, section: string, label: string, amount: string, is_total: bool, is_system: bool, is_input: bool}
     */
    private function headerLine(string $section, string $label): array
    {
        return [
            'key' => 'header_'.$section,
            'section' => $section,
            'label' => $label,
            'amount' => '0.00',
            'is_total' => false,
            'is_system' => true,
            'is_input' => false,
        ];
    }

    private function money(string|float|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
