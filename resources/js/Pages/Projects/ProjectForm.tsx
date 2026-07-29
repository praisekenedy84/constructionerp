import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { ProjectStatus } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent, useMemo } from 'react';

const complianceRuleTypes = [
    { key: 'retention', label: 'Retention' },
    { key: 'advance_recovery', label: 'Advance Recovery' },
    { key: 'wht', label: 'Withholding Tax' },
    { key: 'defect_liability', label: 'Defect Liability' },
    { key: 'material_test', label: 'Material Test' },
    { key: 'hiv_report', label: 'HIV Report' },
] as const;

type ComplianceRuleKey = (typeof complianceRuleTypes)[number]['key'];

export interface ComplianceRuleForm {
    rule_type: ComplianceRuleKey;
    rate: string;
    is_active: boolean;
    max_amount: string;
}

export interface ProjectFormValues {
    code: string;
    name: string;
    client: string;
    location: string;
    contract_amount: string;
    wht_percentage: string;
    start_date: string;
    end_date: string;
    status: ProjectStatus;
    compliance_rules: ComplianceRuleForm[];
}

interface ProjectFormProps {
    mode: 'create' | 'edit';
    projectId?: number;
    initial: ProjectFormValues;
}

const statusOptions: { value: ProjectStatus; label: string }[] = [
    { value: 'planning', label: 'Planning' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'closed', label: 'Closed' },
];

export function defaultComplianceRules(): ComplianceRuleForm[] {
    return complianceRuleTypes.map((r) => ({
        rule_type: r.key,
        rate: r.key === 'wht' ? '5' : '',
        is_active: r.key === 'wht',
        max_amount: '',
    }));
}

export function mergeComplianceRules(
    saved: Array<{
        rule_type: string;
        rate: string;
        is_active: boolean;
        max_amount: string;
    }>,
): ComplianceRuleForm[] {
    return complianceRuleTypes.map((meta) => {
        const existing = saved.find((rule) => rule.rule_type === meta.key);

        if (!existing) {
            return {
                rule_type: meta.key,
                rate: '',
                is_active: false,
                max_amount: '',
            };
        }

        return {
            rule_type: meta.key,
            rate: existing.rate === '0' || existing.rate === '0.00' ? '' : existing.rate,
            is_active: existing.is_active,
            max_amount: existing.max_amount ?? '',
        };
    });
}

function parseNumber(value: string | number | null | undefined): number {
    if (value === null || value === undefined || value === '') {
        return 0;
    }

    const num = typeof value === 'number' ? value : parseFloat(value);

    return Number.isNaN(num) ? 0 : num;
}

function chargeAmount(contract: number, rate: string, fixedAmount: string): number {
    const rateValue = parseNumber(rate);
    const fixed = fixedAmount !== '' ? parseNumber(fixedAmount) : 0;
    const fromPercent = rateValue > 0 ? (contract * rateValue) / 100 : 0;

    if (fromPercent > 0 && fixed > 0) {
        return Math.min(fromPercent, fixed);
    }

    if (fromPercent > 0) {
        return fromPercent;
    }

    return Math.max(fixed, 0);
}

function chargeLabel(rule: ComplianceRuleForm, metaLabel: string): string {
    const rate = parseNumber(rule.rate);
    const fixed = rule.max_amount !== '' ? parseNumber(rule.max_amount) : 0;

    if (rate > 0 && fixed > 0) {
        return `${metaLabel} (${rate}% · max ${formatCurrency(fixed)})`;
    }

    if (rate > 0) {
        return `${metaLabel} (${rate}%)`;
    }

    if (fixed > 0) {
        return `${metaLabel} (fixed)`;
    }

    return metaLabel;
}

export default function ProjectForm({ mode, projectId, initial }: ProjectFormProps) {
    const { data, setData, post, put, processing, errors } = useForm(initial);

    const calculation = useMemo(() => {
        const contract = parseNumber(data.contract_amount);
        const lines: { key: string; label: string; amount: number }[] = [];

        for (const rule of data.compliance_rules) {
            if (!rule.is_active) {
                continue;
            }

            const amount = chargeAmount(contract, rule.rate, rule.max_amount);
            if (amount <= 0) {
                continue;
            }

            const meta = complianceRuleTypes.find((r) => r.key === rule.rule_type);

            lines.push({
                key: rule.rule_type,
                label: chargeLabel(rule, meta?.label ?? rule.rule_type),
                amount,
            });
        }

        const totalCharges = lines.reduce((sum, line) => sum + line.amount, 0);

        return {
            contract,
            lines,
            totalCharges,
            remaining: Math.max(contract - totalCharges, 0),
        };
    }, [data.contract_amount, data.compliance_rules]);

    const hasComplianceError = Object.keys(errors).some((key) => key.startsWith('compliance_rules'));
    const title = mode === 'create' ? 'Create Project' : 'Edit Project';
    const headTitle = mode === 'create' ? 'New Project' : `Edit ${data.code || 'Project'}`;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (mode === 'create') {
            post('/projects');
            return;
        }
        put(`/projects/${projectId}`);
    }

    function updateRule(index: number, field: keyof ComplianceRuleForm, value: string | boolean) {
        const rules = data.compliance_rules.map((rule, i) =>
            i === index ? { ...rule, [field]: value } : rule,
        );

        if (rules[index].rule_type === 'wht') {
            const next: typeof data = { ...data, compliance_rules: rules };
            if (field === 'rate' && typeof value === 'string' && value !== '') {
                next.wht_percentage = value;
            }
            if (field === 'is_active') {
                next.wht_percentage =
                    value === true ? rules[index].rate || data.wht_percentage || '5' : '0';
            }
            setData(next);
            return;
        }

        setData('compliance_rules', rules);
    }

    return (
        <AppShell title={headTitle}>
            <Head title={headTitle} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={title}
                    description="Remaining amount updates as you enter the contract value and toggle compliance charges."
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">Project Details</h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="code">Project Code</Label>
                                <Input
                                    id="code"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    required
                                />
                                {errors.code && <p className="text-sm text-red-600">{errors.code}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="name">Project Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client">Client</Label>
                                <Input
                                    id="client"
                                    value={data.client}
                                    onChange={(e) => setData('client', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="contract_amount">Contract Amount (TZS)</Label>
                                <AmountInput
                                    id="contract_amount"
                                    value={data.contract_amount}
                                    onValueChange={(v) => setData('contract_amount', v)}
                                    required
                                />
                                {errors.contract_amount && (
                                    <p className="text-sm text-red-600">{errors.contract_amount}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="status">Status</Label>
                                <select
                                    id="status"
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value as ProjectStatus)}
                                >
                                    {statusOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="start_date">Start Date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={data.start_date}
                                    onChange={(e) => setData('start_date', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="end_date">End Date</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={data.end_date}
                                    onChange={(e) => setData('end_date', e.target.value)}
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">
                            Compliance Rules
                        </h3>
                        <div className="space-y-3">
                            {complianceRuleTypes.map((rule, index) => {
                                const formRule = data.compliance_rules[index];
                                const preview = formRule.is_active
                                    ? chargeAmount(
                                          calculation.contract,
                                          formRule.rate,
                                          formRule.max_amount,
                                      )
                                    : 0;

                                return (
                                    <div
                                        key={rule.key}
                                        className="flex flex-wrap items-center gap-4 rounded-lg border border-slate-100 p-3"
                                    >
                                        <label className="flex min-w-[10rem] items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={formRule.is_active}
                                                onChange={(e) =>
                                                    updateRule(index, 'is_active', e.target.checked)
                                                }
                                                className="rounded border-slate-300"
                                            />
                                            {rule.label}
                                        </label>
                                        <Input
                                            type="number"
                                            step="0.01"
                                            placeholder="Rate %"
                                            className="w-24"
                                            value={formRule.rate}
                                            onChange={(e) =>
                                                updateRule(index, 'rate', e.target.value)
                                            }
                                            disabled={!formRule.is_active}
                                        />
                                        <AmountInput
                                            placeholder="Fixed amount"
                                            className="w-36"
                                            value={formRule.max_amount}
                                            onValueChange={(v) =>
                                                updateRule(index, 'max_amount', v)
                                            }
                                            disabled={!formRule.is_active}
                                        />
                                        {preview > 0 && (
                                            <span className="text-xs font-medium text-red-600">
                                                −{formatCurrency(preview)}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                        {hasComplianceError && (
                            <p className="mt-3 text-sm text-red-600">
                                Active compliance rules require a rate % or a fixed amount.
                            </p>
                        )}
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-slate-50 p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">
                            Amount Summary
                        </h3>
                        <dl className="space-y-2 text-sm">
                            <div className="flex items-center justify-between gap-4">
                                <dt className="text-slate-600">Contract Amount</dt>
                                <dd className="font-medium text-slate-900">
                                    {formatCurrency(calculation.contract || null)}
                                </dd>
                            </div>
                            {calculation.lines.map((line) => (
                                <div
                                    key={line.key}
                                    className="flex items-center justify-between gap-4 text-red-700"
                                >
                                    <dt>− {line.label}</dt>
                                    <dd>−{formatCurrency(line.amount)}</dd>
                                </div>
                            ))}
                            <div className="flex items-center justify-between gap-4 border-t border-slate-200 pt-3">
                                <dt className="font-semibold text-slate-900">Remaining Amount</dt>
                                <dd className="text-lg font-bold text-slate-900">
                                    {formatCurrency(calculation.remaining || null)}
                                </dd>
                            </div>
                            {calculation.totalCharges > 0 && (
                                <p className="pt-1 text-xs text-slate-500">
                                    Total charges {formatCurrency(calculation.totalCharges)}{' '}
                                    deducted from the contract. Uncheck a charge to add it back.
                                </p>
                            )}
                        </dl>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link href={mode === 'edit' && projectId ? `/projects/${projectId}` : '/projects'}>
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? mode === 'create'
                                    ? 'Creating…'
                                    : 'Saving…'
                                : mode === 'create'
                                  ? 'Create Project'
                                  : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
