import AppShell from '@/Components/Layout/AppShell';
import ComplianceItemsEditor, {
    AvailableComplianceRule,
    ComplianceItemForm,
    computeItemAmount,
    emptyComplianceItem,
} from '@/Components/Domain/ComplianceItemsEditor';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { ProjectStatus } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { FormEvent, useMemo } from 'react';

export interface ProjectIpcForm {
    compliance_items: ComplianceItemForm[];
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
    initial_phase_name?: string;
    initial_phase_disbursed_amount?: string;
    ipcs?: ProjectIpcForm[];
}

interface ProjectFormProps {
    mode: 'create' | 'edit';
    projectId?: number;
    initial: ProjectFormValues;
    availableRules?: AvailableComplianceRule[];
}

const statusOptions: { value: ProjectStatus; label: string }[] = [
    { value: 'planning', label: 'Planning' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'closed', label: 'Closed' },
];

function emptyIpc(): ProjectIpcForm {
    return { compliance_items: [emptyComplianceItem()] };
}

function parseNumber(value: string | number | null | undefined): number {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    const num = typeof value === 'number' ? value : parseFloat(value);
    return Number.isNaN(num) ? 0 : num;
}

function ipcTotal(ipc: ProjectIpcForm, contract: number): number {
    return ipc.compliance_items.reduce(
        (sum, item) => sum + computeItemAmount(item, contract),
        0,
    );
}

export default function ProjectForm({
    mode,
    projectId,
    initial,
    availableRules = [],
}: ProjectFormProps) {
    const { data, setData, post, put, processing, errors } = useForm({
        ...initial,
        initial_phase_name: initial.initial_phase_name ?? 'Phase 1',
        initial_phase_disbursed_amount: initial.initial_phase_disbursed_amount ?? '',
        ipcs: initial.ipcs ?? (mode === 'create' ? [emptyIpc()] : []),
    });

    const title = mode === 'create' ? 'Create Project' : 'Edit Project';
    const headTitle = mode === 'create' ? 'New Project' : `Edit ${data.code || 'Project'}`;
    const showIpcs = mode === 'create';
    const contract = parseNumber(data.contract_amount);
    const phaseDisbursed = parseNumber(data.initial_phase_disbursed_amount);

    const projectSummary = useMemo(() => {
        const ipcs = data.ipcs ?? [];
        const totals = ipcs.map((ipc, index) => ({
            index,
            total: ipcTotal(ipc, phaseDisbursed),
        }));
        const complianceSum = totals.reduce((sum, row) => sum + row.total, 0);

        return {
            totals,
            complianceSum,
            netProject: Math.max(phaseDisbursed - complianceSum, 0),
        };
    }, [phaseDisbursed, data.ipcs]);

    function submit(e: FormEvent) {
        e.preventDefault();
        if (mode === 'create') {
            post('/projects');
            return;
        }
        put(`/projects/${projectId}`);
    }

    function updateIpcItems(index: number, items: ComplianceItemForm[]) {
        const next = (data.ipcs ?? []).map((ipc, i) =>
            i === index ? { ...ipc, compliance_items: items } : ipc,
        );
        setData('ipcs', next);
    }

    function addIpc() {
        setData('ipcs', [...(data.ipcs ?? []), emptyIpc()]);
    }

    function removeIpc(index: number) {
        const next = (data.ipcs ?? []).filter((_, i) => i !== index);
        setData('ipcs', next.length > 0 ? next : [emptyIpc()]);
    }

    return (
        <AppShell title={headTitle}>
            <Head title={headTitle} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={title}
                    description={
                        showIpcs
                            ? 'Enter project details, record the first client disbursement as Phase 1, then add Phase 1 IPCs.'
                            : 'Update project details. Manage phases and IPCs from the project page.'
                    }
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
                                    onChange={(e) =>
                                        setData('status', e.target.value as ProjectStatus)
                                    }
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

                    {showIpcs && (
                        <div className="space-y-4">
                            <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                                <h3 className="mb-1 text-sm font-semibold text-slate-900">
                                    Initial Phase (client disbursement)
                                </h3>
                                <p className="mb-4 text-xs text-slate-500">
                                    Record the first batch paid by the client. IPC rate % uses this
                                    amount, not the full contract.
                                </p>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <div className="space-y-2">
                                        <Label htmlFor="initial_phase_name">Phase name</Label>
                                        <Input
                                            id="initial_phase_name"
                                            value={data.initial_phase_name ?? ''}
                                            onChange={(e) =>
                                                setData('initial_phase_name', e.target.value)
                                            }
                                            placeholder="Phase 1"
                                        />
                                        {errors.initial_phase_name && (
                                            <p className="text-sm text-red-600">
                                                {errors.initial_phase_name}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="initial_phase_disbursed_amount">
                                            Client disbursed amount (TZS)
                                        </Label>
                                        <AmountInput
                                            id="initial_phase_disbursed_amount"
                                            value={data.initial_phase_disbursed_amount ?? ''}
                                            onValueChange={(v) =>
                                                setData('initial_phase_disbursed_amount', v)
                                            }
                                        />
                                        {errors.initial_phase_disbursed_amount && (
                                            <p className="text-sm text-red-600">
                                                {errors.initial_phase_disbursed_amount}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            </div>

                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">
                                        Phase 1 IPCs
                                    </h3>
                                    <p className="text-xs text-slate-500">
                                        Add IPC-1, IPC-2, … for this initial disbursement phase.
                                    </p>
                                </div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={addIpc}
                                    disabled={availableRules.length === 0}
                                >
                                    <Plus className="h-4 w-4" />
                                    Add IPC
                                </Button>
                            </div>

                            {availableRules.length === 0 ? (
                                <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-6 text-sm text-amber-900">
                                    <p className="font-medium">No compliance rules defined yet.</p>
                                    <p className="mt-1">
                                        Create rules under{' '}
                                        <Link
                                            href="/projects/compliance-rules"
                                            className="underline"
                                        >
                                            Projects → Compliance Rules
                                        </Link>{' '}
                                        first, or create the project now and add IPCs later.
                                    </p>
                                </div>
                            ) : (
                                (data.ipcs ?? []).map((ipc, index) => (
                                    <div
                                        key={index}
                                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"
                                    >
                                        <div className="mb-4 flex items-center justify-between gap-3">
                                            <h4 className="text-sm font-semibold text-slate-900">
                                                IPC-{index + 1}
                                            </h4>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => removeIpc(index)}
                                                aria-label={`Remove IPC-${index + 1}`}
                                                disabled={(data.ipcs ?? []).length <= 1}
                                            >
                                                <Trash2 className="h-4 w-4 text-slate-500" />
                                            </Button>
                                        </div>
                                        <ComplianceItemsEditor
                                            items={ipc.compliance_items}
                                            availableRules={availableRules}
                                            baseAmount={data.initial_phase_disbursed_amount ?? '0'}
                                            baseLabel="Phase 1 disbursed amount"
                                            ipcLabel={`IPC-${index + 1}`}
                                            errorPrefix={`ipcs.${index}`}
                                            summaryMode="ipc-only"
                                            hideHeader
                                            errors={errors}
                                            onChange={(items) => updateIpcItems(index, items)}
                                        />
                                    </div>
                                ))
                            )}

                            {errors.ipcs && (
                                <p className="text-sm text-red-600">{errors.ipcs}</p>
                            )}

                            <div className="rounded-xl border border-slate-200 bg-slate-50 p-6 shadow-sm">
                                <h3 className="mb-4 text-sm font-semibold text-slate-900">
                                    Amount Summary
                                </h3>
                                <dl className="space-y-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-slate-600">Contract amount</dt>
                                        <dd className="font-medium">
                                            {formatCurrency(contract || null)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <dt className="text-slate-600">
                                            Phase 1 client disbursement
                                        </dt>
                                        <dd className="font-medium">
                                            {formatCurrency(phaseDisbursed || null)}
                                        </dd>
                                    </div>
                                    {projectSummary.totals
                                        .filter((row) => row.total > 0)
                                        .map((row) => (
                                            <div
                                                key={row.index}
                                                className="flex justify-between gap-4 text-red-700"
                                            >
                                                <dt>− Total IPC-{row.index + 1}</dt>
                                                <dd>−{formatCurrency(row.total)}</dd>
                                            </div>
                                        ))}
                                    <div className="flex justify-between gap-4 border-t border-slate-200 pt-3">
                                        <dt className="font-semibold text-slate-900">
                                            Initial net budget
                                        </dt>
                                        <dd className="text-lg font-bold text-slate-900">
                                            {formatCurrency(projectSummary.netProject)}
                                        </dd>
                                    </div>
                                    <p className="pt-1 text-xs text-slate-500">
                                        Initial net budget = Phase 1 disbursement − Phase 1 IPCs
                                        compliance (held retention can be released later)
                                    </p>
                                </dl>
                            </div>
                        </div>
                    )}

                    <div className="flex justify-end gap-3">
                        <Link
                            href={
                                mode === 'edit' && projectId
                                    ? `/projects/${projectId}`
                                    : '/projects'
                            }
                        >
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
