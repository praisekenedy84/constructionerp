import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { PageProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

type InputMode = 'fixed' | 'percent';

interface StatementLine {
    key: string;
    section: string;
    label: string;
    amount: string;
    is_total: boolean;
    is_system: boolean;
    is_input: boolean;
}

interface AdhocRow {
    label: string;
    value: string;
    mode: InputMode;
    section: string;
    amount?: string;
}

interface Statement {
    memo_no: string | null;
    period: { from: string | null; to: string | null; project_id: number | null };
    lines: StatementLine[];
    totals: {
        total_revenue: string;
        total_direct: string;
        total_indirect: string;
        total_expenses: string;
        ebitda: string;
        interest: string;
        depreciation: string;
        interest_depreciation: string;
        corporate_tax: string;
        loss_recognition: string;
        net_profit: string;
    };
    bases: {
        revenue: string;
        direct: string;
        indirect: string;
        ebitda: string;
    };
    adjustments: {
        interest: string;
        interest_mode: InputMode;
        interest_value: string;
        depreciation: string;
        depreciation_mode: InputMode;
        depreciation_value: string;
        corporate_tax: string;
        corporate_tax_mode: InputMode;
        corporate_tax_value: string;
        adhoc: AdhocRow[];
    };
    projects: Array<{ id: number; code: string; name: string }>;
}

interface IncomeStatementProps extends PageProps {
    mode: 'draft' | 'final';
    statement: Statement;
    filters: {
        from: string;
        to: string;
        project_id: string;
        memo_no: string;
    };
}

const SECTION_OPTIONS = [
    { value: 'revenue', label: 'Revenue' },
    { value: 'direct', label: 'Direct expenses' },
    { value: 'indirect', label: 'Indirect expenses (Administrative)' },
    { value: 'below_ebitda', label: 'Below EBITDA' },
];

function isHeader(line: StatementLine): boolean {
    return line.key.startsWith('header_');
}

function money(value: string | number | null | undefined): number {
    const n = typeof value === 'string' ? parseFloat(value) : Number(value ?? 0);
    return Number.isFinite(n) ? n : 0;
}

function resolveAmount(mode: InputMode, value: string, base: number): string {
    const v = money(value);
    if (mode === 'percent') {
        return ((base * v) / 100).toFixed(2);
    }
    return v.toFixed(2);
}

function labeledAmount(label: string, mode: InputMode, value: string): string {
    if (mode === 'percent' && money(value) > 0) {
        return `${label} @ ${money(value).toFixed(2)}%`;
    }
    return label;
}

function line(
    key: string,
    section: string,
    label: string,
    amount: string | number,
    flags: Partial<Pick<StatementLine, 'is_total' | 'is_system' | 'is_input'>> = {},
): StatementLine {
    return {
        key,
        section,
        label,
        amount: typeof amount === 'number' ? amount.toFixed(2) : amount,
        is_total: flags.is_total ?? false,
        is_system: flags.is_system ?? false,
        is_input: flags.is_input ?? false,
    };
}

function ModeToggle({
    value,
    onChange,
    id,
}: {
    value: InputMode;
    onChange: (mode: InputMode) => void;
    id: string;
}) {
    return (
        <div className="flex rounded-md border border-slate-200 bg-white p-0.5 text-xs">
            <button
                type="button"
                id={`${id}-fixed`}
                className={`flex-1 rounded px-2 py-1 ${value === 'fixed' ? 'bg-slate-900 text-white' : 'text-slate-600'}`}
                onClick={() => onChange('fixed')}
            >
                Amount
            </button>
            <button
                type="button"
                id={`${id}-percent`}
                className={`flex-1 rounded px-2 py-1 ${value === 'percent' ? 'bg-slate-900 text-white' : 'text-slate-600'}`}
                onClick={() => onChange('percent')}
            >
                % rate
            </button>
        </div>
    );
}

function AdjustmentField({
    id,
    label,
    mode,
    value,
    base,
    baseLabel,
    onModeChange,
    onValueChange,
    error,
}: {
    id: string;
    label: string;
    mode: InputMode;
    value: string;
    base: number;
    baseLabel: string;
    onModeChange: (mode: InputMode) => void;
    onValueChange: (value: string) => void;
    error?: string;
}) {
    const computed = resolveAmount(mode, value, base);

    return (
        <div className="space-y-2 rounded-md bg-amber-50 p-3">
            <Label htmlFor={id}>{label}</Label>
            <ModeToggle id={id} value={mode} onChange={onModeChange} />
            <AmountInput
                id={id}
                value={value}
                onValueChange={onValueChange}
                placeholder={mode === 'percent' ? 'e.g. 30' : '0'}
            />
            <div className="text-xs text-slate-600">
                {mode === 'percent' ? (
                    <>
                        {baseLabel}: {formatCurrency(base.toFixed(2))} →{' '}
                        <span className="font-medium text-slate-900">{formatCurrency(computed)}</span>
                    </>
                ) : (
                    <>
                        Amount:{' '}
                        <span className="font-medium text-slate-900">{formatCurrency(computed)}</span>
                    </>
                )}
            </div>
            {error && <p className="text-xs text-red-600">{error}</p>}
        </div>
    );
}

function StatementTable({ lines }: { lines: StatementLine[] }) {
    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[32rem] text-sm">
                <thead>
                    <tr className="border-b border-slate-200 text-left text-slate-500">
                        <th className="py-2 font-medium">Line</th>
                        <th className="py-2 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {lines.map((row) => {
                        const header = isHeader(row);
                        return (
                            <tr
                                key={row.key + row.label}
                                className={
                                    row.is_total
                                        ? 'border-t border-slate-300 font-semibold'
                                        : header
                                          ? 'border-t border-transparent'
                                          : ''
                                }
                            >
                                <td
                                    className={
                                        header
                                            ? 'pt-4 pb-1 font-semibold text-slate-800'
                                            : row.is_total
                                              ? 'py-2'
                                              : 'py-1.5 pl-6 text-slate-700'
                                    }
                                >
                                    {row.label}
                                </td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {header ? null : formatCurrency(row.amount)}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

export default function IncomeStatement() {
    const { mode, statement, filters } = usePage<IncomeStatementProps>().props;
    const isFinal = mode === 'final';
    const bases = statement.bases ?? {
        revenue: statement.totals.total_revenue,
        direct: statement.totals.total_direct,
        indirect: statement.totals.total_indirect,
        ebitda: statement.totals.ebitda,
    };

    const [filterState, setFilterState] = useState({
        from: filters.from ?? '',
        to: filters.to ?? '',
        project_id: filters.project_id ?? '',
        memo_no: filters.memo_no ?? '',
    });

    const [interest, setInterest] = useState(
        statement.adjustments.interest_value ?? statement.adjustments.interest ?? '0',
    );
    const [interestMode, setInterestMode] = useState<InputMode>(
        statement.adjustments.interest_mode ?? 'fixed',
    );
    const [depreciation, setDepreciation] = useState(
        statement.adjustments.depreciation_value ?? statement.adjustments.depreciation ?? '0',
    );
    const [depreciationMode, setDepreciationMode] = useState<InputMode>(
        statement.adjustments.depreciation_mode ?? 'fixed',
    );
    const [corporateTax, setCorporateTax] = useState(
        statement.adjustments.corporate_tax_value ?? statement.adjustments.corporate_tax ?? '0',
    );
    const [corporateTaxMode, setCorporateTaxMode] = useState<InputMode>(
        statement.adjustments.corporate_tax_mode ?? 'fixed',
    );
    const [adhoc, setAdhoc] = useState<AdhocRow[]>(
        statement.adjustments.adhoc.length
            ? statement.adjustments.adhoc.map((row) => ({
                  label: row.label,
                  value: row.value ?? row.amount ?? '',
                  mode: (row.mode ?? 'fixed') as InputMode,
                  section: row.section,
              }))
            : [],
    );

    const form = useForm({
        from: filters.from ?? '',
        to: filters.to ?? '',
        project_id: filters.project_id ?? '',
        memo_no: filters.memo_no ?? '',
    });

    const live = useMemo(() => {
        const systemDetails = (section: string) =>
            statement.lines.filter(
                (row) =>
                    row.section === section &&
                    row.is_system &&
                    !row.is_total &&
                    !isHeader(row) &&
                    row.key !== 'loss_recognition',
            );

        const resolveSectionAdhoc = (section: string, base: number) =>
            adhoc
                .filter((row) => row.section === section && row.label.trim() !== '')
                .map((row) => ({
                    ...row,
                    amount: resolveAmount(row.mode, row.value, base),
                }));

        const revenueAdhoc = resolveSectionAdhoc('revenue', money(bases.revenue));
        const directAdhoc = resolveSectionAdhoc('direct', money(bases.direct));
        const indirectAdhoc = resolveSectionAdhoc('indirect', money(bases.indirect));

        const revenue =
            money(bases.revenue) + revenueAdhoc.reduce((s, r) => s + money(r.amount), 0);
        const direct = money(bases.direct) + directAdhoc.reduce((s, r) => s + money(r.amount), 0);
        const indirect =
            money(bases.indirect) + indirectAdhoc.reduce((s, r) => s + money(r.amount), 0);
        const expenses = direct + indirect;
        const ebitda = revenue - expenses;

        const interestAmt = money(resolveAmount(interestMode, interest, ebitda));
        const depreciationAmt = money(resolveAmount(depreciationMode, depreciation, ebitda));
        const belowAdhoc = resolveSectionAdhoc('below_ebitda', ebitda);
        const belowTotal = belowAdhoc.reduce((s, r) => s + money(r.amount), 0);
        const interestDepreciation = interestAmt + depreciationAmt + belowTotal;
        const profitBeforeTax = ebitda - interestDepreciation;
        const corporateTaxAmt = money(
            resolveAmount(corporateTaxMode, corporateTax, profitBeforeTax),
        );
        const lossRecognition = money(statement.totals.loss_recognition);
        const netProfit = profitBeforeTax - corporateTaxAmt - lossRecognition;

        const pushAdhoc = (section: string, rows: Array<AdhocRow & { amount: string }>) =>
            rows.map((row) =>
                line(
                    `adhoc_${section}_${row.label}`,
                    section,
                    labeledAmount(row.label, row.mode, row.value),
                    row.amount,
                    { is_input: true },
                ),
            );

        const lines: StatementLine[] = [
            line('header_revenue', 'revenue', 'Total Revenue', 0),
            ...systemDetails('revenue'),
            ...pushAdhoc('revenue', revenueAdhoc),
            line('total_revenue', 'revenue', 'Total Revenue', revenue, {
                is_total: true,
                is_system: true,
            }),

            line('header_direct', 'direct', 'Less: Direct Expenses (Operational)', 0),
            ...systemDetails('direct'),
            ...pushAdhoc('direct', directAdhoc),
            line('total_direct', 'direct', 'Total Direct Expenses', direct, {
                is_total: true,
                is_system: true,
            }),

            line(
                'header_indirect',
                'indirect',
                'Less: Indirect Expenses (Administrative Expenses)',
                0,
            ),
            ...systemDetails('indirect'),
            ...pushAdhoc('indirect', indirectAdhoc),
            line(
                'total_indirect',
                'indirect',
                'Total Indirect Expenses (Administrative Expenses)',
                indirect,
                {
                    is_total: true,
                    is_system: true,
                },
            ),

            line('total_expenses', 'summary', 'Total Expenses (Direct + Indirect)', expenses, {
                is_total: true,
                is_system: true,
            }),
            line('ebitda', 'summary', 'Profit Before Tax (EBITDA)', ebitda, {
                is_total: true,
                is_system: true,
            }),

            line('header_below_ebitda', 'below_ebitda', 'Less:', 0),
            line(
                'interest',
                'below_ebitda',
                labeledAmount('Interest (Bank/Corporate)', interestMode, interest),
                interestAmt,
                { is_input: true },
            ),
            line(
                'depreciation',
                'below_ebitda',
                labeledAmount('Depreciation', depreciationMode, depreciation),
                depreciationAmt,
                { is_input: true },
            ),
            ...pushAdhoc('below_ebitda', belowAdhoc),
            line(
                'interest_depreciation',
                'below_ebitda',
                'Total Interest & Depreciation',
                interestDepreciation,
                { is_total: true, is_system: true },
            ),
            line(
                'corporate_tax',
                'below_ebitda',
                labeledAmount('Corporate Tax', corporateTaxMode, corporateTax),
                corporateTaxAmt,
                { is_input: true },
            ),
        ];

        if (lossRecognition > 0) {
            lines.push(
                line('loss_recognition', 'below_ebitda', 'Loss recognition', lossRecognition, {
                    is_system: true,
                }),
            );
        }

        lines.push(
            line('net_profit', 'summary', 'Net Profit', netProfit, {
                is_total: true,
                is_system: true,
            }),
        );

        return {
            lines,
            ebitda,
            interest: interestAmt,
            depreciation: depreciationAmt,
            profitBeforeTax,
            corporateTax: corporateTaxAmt,
            netProfit,
            sectionBase: (section: string) => {
                if (section === 'revenue') return money(bases.revenue);
                if (section === 'direct') return money(bases.direct);
                if (section === 'indirect') return money(bases.indirect);
                return ebitda;
            },
            sectionBaseLabel: (section: string) => {
                if (section === 'revenue') return 'of system revenue';
                if (section === 'direct') return 'of system direct';
                if (section === 'indirect') return 'of system indirect';
                return 'of EBITDA';
            },
        };
    }, [
        statement.lines,
        statement.totals.loss_recognition,
        bases,
        interest,
        interestMode,
        depreciation,
        depreciationMode,
        corporateTax,
        corporateTaxMode,
        adhoc,
    ]);

    const displayLines = isFinal ? statement.lines : live.lines;

    function applyFilters() {
        router.get(
            '/finance/income-statement',
            {
                from: filterState.from || undefined,
                to: filterState.to || undefined,
                project_id: filterState.project_id || undefined,
                memo_no: filterState.memo_no || undefined,
            },
            { preserveState: false },
        );
    }

    function payloadFromForm() {
        return {
            from: filterState.from || null,
            to: filterState.to || null,
            project_id: filterState.project_id || null,
            memo_no: filterState.memo_no || null,
            interest: interest || '0',
            interest_mode: interestMode,
            depreciation: depreciation || '0',
            depreciation_mode: depreciationMode,
            corporate_tax: corporateTax || '0',
            corporate_tax_mode: corporateTaxMode,
            adhoc: adhoc
                .filter((row) => row.label.trim() !== '')
                .map((row) => ({
                    label: row.label,
                    value: row.value || '0',
                    mode: row.mode,
                    section: row.section,
                })),
        };
    }

    function generateFinal(e: FormEvent) {
        e.preventDefault();
        form.transform(() => payloadFromForm());
        form.post('/finance/income-statement/finalize');
    }

    function backToDraft() {
        router.get('/finance/income-statement', {
            from: filterState.from || undefined,
            to: filterState.to || undefined,
            project_id: filterState.project_id || undefined,
            memo_no: filterState.memo_no || undefined,
        });
    }

    async function exportStatement(format: 'csv' | 'xlsx' | 'pdf') {
        const xsrf = decodeURIComponent(
            document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '',
        );

        const response = await fetch('/finance/income-statement/export', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/octet-stream',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': xsrf,
            },
            body: JSON.stringify({
                format,
                from: filterState.from || null,
                to: filterState.to || null,
                project_id: filterState.project_id || null,
                memo_no: filterState.memo_no || null,
                interest: statement.adjustments.interest_value ?? statement.adjustments.interest,
                interest_mode: statement.adjustments.interest_mode ?? 'fixed',
                depreciation:
                    statement.adjustments.depreciation_value ?? statement.adjustments.depreciation,
                depreciation_mode: statement.adjustments.depreciation_mode ?? 'fixed',
                corporate_tax:
                    statement.adjustments.corporate_tax_value ?? statement.adjustments.corporate_tax,
                corporate_tax_mode: statement.adjustments.corporate_tax_mode ?? 'fixed',
                adhoc: statement.adjustments.adhoc.map((row) => ({
                    label: row.label,
                    value: row.value ?? row.amount ?? '0',
                    mode: row.mode ?? 'fixed',
                    section: row.section,
                })),
            }),
        });

        if (!response.ok) {
            return;
        }

        const blob = await response.blob();
        const disposition = response.headers.get('Content-Disposition') ?? '';
        const matched = disposition.match(/filename="?([^"]+)"?/i);
        const filename = matched?.[1] ?? `income-statement.${format}`;
        const url = URL.createObjectURL(blob);
        const anchor = document.createElement('a');
        anchor.href = url;
        anchor.download = filename;
        anchor.click();
        URL.revokeObjectURL(url);
    }

    function addAdhoc() {
        setAdhoc((rows) => [
            ...rows,
            { label: '', value: '', mode: 'fixed', section: 'below_ebitda' },
        ]);
    }

    function updateAdhoc(index: number, patch: Partial<AdhocRow>) {
        setAdhoc((rows) => rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    }

    function removeAdhoc(index: number) {
        setAdhoc((rows) => rows.filter((_, i) => i !== index));
    }

    return (
        <AppShell title="Income Statement">
            <Head title="Income Statement" />
            <div className="space-y-6">
                <PageHeader
                    title="Income Statement"
                    description={
                        isFinal
                            ? 'Final statement with your adjustments applied. Export when ready.'
                            : 'Draft updates live as you type. Enter fixed amounts or % rates, then generate the final preview to export.'
                    }
                    actions={
                        isFinal ? (
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" size="sm" onClick={backToDraft}>
                                    Back to adjust
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => exportStatement('csv')}
                                >
                                    <Download className="h-4 w-4" />
                                    CSV
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => exportStatement('xlsx')}
                                >
                                    <Download className="h-4 w-4" />
                                    XLSX
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => exportStatement('pdf')}
                                >
                                    <Download className="h-4 w-4" />
                                    PDF
                                </Button>
                            </div>
                        ) : null
                    }
                />

                <DataPanel title="Period">
                    <div className="flex flex-wrap items-end gap-4">
                        <div className="space-y-2">
                            <Label htmlFor="memo_no">Memo No.</Label>
                            <Input
                                id="memo_no"
                                value={filterState.memo_no}
                                disabled={isFinal}
                                onChange={(e) =>
                                    setFilterState((s) => ({ ...s, memo_no: e.target.value }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="from">From</Label>
                            <Input
                                id="from"
                                type="date"
                                value={filterState.from}
                                disabled={isFinal}
                                onChange={(e) =>
                                    setFilterState((s) => ({ ...s, from: e.target.value }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="to">To</Label>
                            <Input
                                id="to"
                                type="date"
                                value={filterState.to}
                                disabled={isFinal}
                                onChange={(e) =>
                                    setFilterState((s) => ({ ...s, to: e.target.value }))
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="project_id">Project</Label>
                            <select
                                id="project_id"
                                className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                                value={filterState.project_id}
                                disabled={isFinal}
                                onChange={(e) =>
                                    setFilterState((s) => ({
                                        ...s,
                                        project_id: e.target.value,
                                    }))
                                }
                            >
                                <option value="">All projects</option>
                                {statement.projects.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} — {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {!isFinal && (
                            <Button type="button" variant="outline" onClick={applyFilters}>
                                Refresh draft
                            </Button>
                        )}
                    </div>
                </DataPanel>

                {!isFinal && (
                    <DataPanel title="Adjustments">
                        <form onSubmit={generateFinal} className="space-y-6">
                            <p className="text-sm text-slate-600">
                                Choose <strong>Amount</strong> or <strong>% rate</strong>. The
                                statement below recalculates as you type.
                            </p>

                            <div className="grid gap-4 sm:grid-cols-3">
                                <AdjustmentField
                                    id="interest"
                                    label="Interest (Bank/Corporate)"
                                    mode={interestMode}
                                    value={interest}
                                    base={live.ebitda}
                                    baseLabel="of EBITDA"
                                    onModeChange={setInterestMode}
                                    onValueChange={setInterest}
                                    error={form.errors.interest}
                                />
                                <AdjustmentField
                                    id="depreciation"
                                    label="Depreciation"
                                    mode={depreciationMode}
                                    value={depreciation}
                                    base={live.ebitda}
                                    baseLabel="of EBITDA"
                                    onModeChange={setDepreciationMode}
                                    onValueChange={setDepreciation}
                                    error={form.errors.depreciation}
                                />
                                <AdjustmentField
                                    id="corporate_tax"
                                    label="Corporate Tax"
                                    mode={corporateTaxMode}
                                    value={corporateTax}
                                    base={live.profitBeforeTax}
                                    baseLabel="of profit before tax"
                                    onModeChange={setCorporateTaxMode}
                                    onValueChange={setCorporateTax}
                                    error={form.errors.corporate_tax}
                                />
                            </div>

                            <div className="space-y-3">
                                <div className="flex items-center justify-between gap-2">
                                    <Label>Additional lines</Label>
                                    <Button type="button" variant="outline" size="sm" onClick={addAdhoc}>
                                        <Plus className="h-4 w-4" />
                                        Add line
                                    </Button>
                                </div>

                                {adhoc.length === 0 && (
                                    <p className="text-sm text-slate-500">
                                        No extra lines yet. Use this for one-off revenue or costs not
                                        captured above.
                                    </p>
                                )}

                                {adhoc.map((row, index) => {
                                    const base = live.sectionBase(row.section);
                                    const computed = resolveAmount(row.mode, row.value, base);
                                    return (
                                        <div
                                            key={index}
                                            className="grid gap-3 rounded-md bg-amber-50 p-3 lg:grid-cols-[1fr_8rem_9rem_11rem_auto]"
                                        >
                                            <div className="space-y-1">
                                                <Label>Label</Label>
                                                <Input
                                                    value={row.label}
                                                    onChange={(e) =>
                                                        updateAdhoc(index, { label: e.target.value })
                                                    }
                                                    placeholder="e.g. Bank charges"
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Type</Label>
                                                <ModeToggle
                                                    id={`adhoc-${index}`}
                                                    value={row.mode}
                                                    onChange={(m) => updateAdhoc(index, { mode: m })}
                                                />
                                            </div>
                                            <div className="space-y-1">
                                                <Label>
                                                    {row.mode === 'percent' ? 'Rate %' : 'Amount'}
                                                </Label>
                                                <AmountInput
                                                    value={row.value}
                                                    onValueChange={(v) =>
                                                        updateAdhoc(index, { value: v })
                                                    }
                                                />
                                                <p className="text-xs text-slate-600">
                                                    {live.sectionBaseLabel(row.section)} →{' '}
                                                    <span className="font-medium text-slate-900">
                                                        {formatCurrency(computed)}
                                                    </span>
                                                </p>
                                            </div>
                                            <div className="space-y-1">
                                                <Label>Section</Label>
                                                <select
                                                    className="h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                                    value={row.section}
                                                    onChange={(e) =>
                                                        updateAdhoc(index, {
                                                            section: e.target.value,
                                                        })
                                                    }
                                                >
                                                    {SECTION_OPTIONS.map((opt) => (
                                                        <option key={opt.value} value={opt.value}>
                                                            {opt.label}
                                                        </option>
                                                    ))}
                                                </select>
                                            </div>
                                            <div className="flex items-end">
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => removeAdhoc(index)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={form.processing}>
                                    Generate final preview
                                </Button>
                            </div>
                        </form>
                    </DataPanel>
                )}

                <DataPanel
                    title={
                        isFinal
                            ? 'Final statement'
                            : 'Live statement'
                    }
                >
                    {!isFinal && (
                        <p className="mb-3 text-sm text-slate-500">
                            Net profit updates instantly from your inputs above.
                        </p>
                    )}
                    <StatementTable lines={displayLines} />
                </DataPanel>
            </div>
        </AppShell>
    );
}

