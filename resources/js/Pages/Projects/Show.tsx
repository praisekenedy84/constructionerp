import AppShell from '@/Components/Layout/AppShell';
import ComplianceItemsEditor, {
    AvailableComplianceRule,
    ComplianceItemForm,
    emptyComplianceItem,
} from '@/Components/Domain/ComplianceItemsEditor';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Project, ProjectPhase } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Tab = 'overview' | 'boq' | 'budget' | 'requisitions' | 'finance' | 'reports';

interface PhaseIpcForm {
    compliance_items: ComplianceItemForm[];
}

interface ProjectsShowProps extends PageProps {
    project: Project;
    phases: ProjectPhase[];
    available_rules: AvailableComplianceRule[];
    tab?: Tab;
}

const tabs: { key: Tab; label: string; href: (id: number) => string }[] = [
    { key: 'overview', label: 'Overview', href: (id) => `/projects/${id}` },
    { key: 'boq', label: 'BOQ', href: (id) => `/projects/${id}/boq` },
    { key: 'budget', label: 'Budget', href: (id) => `/projects/${id}/budget` },
    { key: 'requisitions', label: 'Requisitions', href: (id) => `/requisitions?project_id=${id}` },
    { key: 'finance', label: 'Finance', href: (id) => `/finance/${id}` },
    { key: 'reports', label: 'Reports', href: (id) => `/reports?project_id=${id}` },
];

function emptyIpc(): PhaseIpcForm {
    return { compliance_items: [emptyComplianceItem()] };
}

export default function ProjectsShow() {
    const {
        project,
        phases,
        available_rules = [],
        tab = 'overview',
        auth,
    } = usePage<ProjectsShowProps>().props;
    const canUpdate = hasPermission(auth.user, 'projects', 'update');
    const canDelete = hasPermission(auth.user, 'projects', 'delete-soft');
    const nextPhaseNo = (phases[phases.length - 1]?.sequence_no ?? 0) + 1;

    const [phaseDialogOpen, setPhaseDialogOpen] = useState(false);
    const {
        data,
        setData,
        post,
        processing,
        errors,
        reset,
        clearErrors,
        isDirty,
    } = useForm<{
        name: string;
        disbursed_amount: string;
        ipcs: PhaseIpcForm[];
    }>({
        name: `Phase ${nextPhaseNo}`,
        disbursed_amount: '',
        ipcs: [],
    });

    function archiveProject() {
        if (!confirm(`Archive project "${project.code} — ${project.name}"?`)) {
            return;
        }

        router.delete(`/projects/${project.id}`);
    }

    function openPhaseDialog() {
        clearErrors();
        reset();
        setData({
            name: `Phase ${nextPhaseNo}`,
            disbursed_amount: '',
            ipcs: [],
        });
        setPhaseDialogOpen(true);
    }

    function closePhaseDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setPhaseDialogOpen(false);
        reset();
        clearErrors();
    }

    function submitPhase(e: FormEvent) {
        e.preventDefault();
        post(`/projects/${project.id}/phases`, {
            onSuccess: () => {
                setPhaseDialogOpen(false);
                reset();
                clearErrors();
            },
        });
    }

    function addIpc() {
        setData('ipcs', [...data.ipcs, emptyIpc()]);
    }

    function removeIpc(index: number) {
        setData(
            'ipcs',
            data.ipcs.filter((_, i) => i !== index),
        );
    }

    function updateIpcItems(index: number, items: ComplianceItemForm[]) {
        setData(
            'ipcs',
            data.ipcs.map((ipc, i) =>
                i === index ? { ...ipc, compliance_items: items } : ipc,
            ),
        );
    }

    return (
        <AppShell title={project.name}>
            <Head title={project.name} />
            <div className="space-y-6">
                <PageHeader
                    title={project.name}
                    description={`${project.code} · ${project.client} · ${project.location}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {canUpdate && (
                                <Link href={`/projects/${project.id}/edit`}>
                                    <Button variant="outline" size="sm">
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                            )}
                            {canDelete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="border-red-200 text-red-700 hover:bg-red-50"
                                    onClick={archiveProject}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Archive
                                </Button>
                            )}
                            <Link href={`/projects/${project.id}/valuations`}>
                                <Button variant="outline" size="sm">
                                    IPCs
                                </Button>
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <DataPanel title="Net Budget">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.net_budget)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Sum of phase budgets after deductions and retention actions
                        </p>
                    </DataPanel>
                    <DataPanel title="Remaining">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(project.remaining_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Contract Amount">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.contract_amount)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Profit">
                        <p className="text-2xl font-bold text-blue-700">
                            {formatPercent(project.profit_percentage)}
                        </p>
                    </DataPanel>
                </div>

                <nav className="flex gap-1 border-b border-slate-200">
                    {tabs.map((t) => (
                        <Link
                            key={t.key}
                            href={t.href(project.id)}
                            className={cn(
                                'px-4 py-2 text-sm font-medium transition-colors',
                                tab === t.key
                                    ? 'border-b-2 border-blue-700 text-blue-700'
                                    : 'text-slate-500 hover:text-slate-900',
                            )}
                        >
                            {t.label}
                        </Link>
                    ))}
                </nav>

                <DataPanel title="Project Overview">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-xs text-slate-500">Status</dt>
                            <dd className="mt-1">
                                <StatusBadge status={String(project.status)} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Start Date</dt>
                            <dd className="mt-1 text-sm text-slate-900">
                                {formatDate(project.start_date)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">End Date</dt>
                            <dd className="mt-1 text-sm text-slate-900">
                                {formatDate(project.end_date)}
                            </dd>
                        </div>
                    </dl>
                </DataPanel>

                <DataPanel title="Phases & Retention" noPadding>
                    <div className="flex items-center justify-between gap-3 border-b border-slate-200 p-4">
                        <p className="text-sm text-slate-600">
                            Add each client disbursement as a phase, optionally with its IPCs.
                        </p>
                        {canUpdate && (
                            <Button type="button" size="sm" onClick={openPhaseDialog}>
                                <Plus className="h-4 w-4" />
                                Add Phase
                            </Button>
                        )}
                    </div>
                    {errors.phase && (
                        <p className="border-b border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                            {errors.phase}
                        </p>
                    )}
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Phase</th>
                                <th className="px-6 py-3 text-right font-medium">Disbursed</th>
                                <th className="px-6 py-3 text-right font-medium">IPCs</th>
                                <th className="px-6 py-3 text-right font-medium">Held</th>
                                <th className="px-6 py-3 text-right font-medium">Released</th>
                                <th className="px-6 py-3 text-right font-medium">Forfeited</th>
                                <th className="px-6 py-3 text-right font-medium">Phase budget</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {phases.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={8}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        No phases added yet.
                                    </td>
                                </tr>
                            ) : (
                                phases.map((phase) => (
                                    <tr key={phase.id}>
                                        <td className="px-6 py-3">
                                            <div className="font-medium">
                                                Phase {phase.sequence_no}: {phase.name}
                                            </div>
                                            <div className="text-xs text-slate-500 capitalize">
                                                {phase.status}
                                            </div>
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            {formatCurrency(phase.disbursed_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-slate-700">
                                            {phase.valuations_count ?? 0}
                                        </td>
                                        <td className="px-6 py-3 text-right text-amber-700">
                                            {formatCurrency(phase.retention_held_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-green-700">
                                            {formatCurrency(phase.retention_released_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-red-700">
                                            {formatCurrency(phase.retention_forfeited_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right font-semibold">
                                            {formatCurrency(phase.phase_net_budget)}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        router.post(
                                                            `/projects/${project.id}/phases/${phase.id}/retention/release`,
                                                        )
                                                    }
                                                    disabled={
                                                        Number(phase.retention_held_amount) <= 0
                                                    }
                                                >
                                                    Release
                                                </Button>
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    className="text-red-700 hover:bg-red-50"
                                                    onClick={() =>
                                                        router.post(
                                                            `/projects/${project.id}/phases/${phase.id}/retention/forfeit`,
                                                        )
                                                    }
                                                    disabled={
                                                        Number(phase.retention_held_amount) <= 0
                                                    }
                                                >
                                                    Forfeit
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </DataPanel>
            </div>

            <Dialog
                open={phaseDialogOpen}
                onOpenChange={(next) => (next ? undefined : closePhaseDialog())}
                title={`Add Phase ${nextPhaseNo}`}
                description="Record the next client disbursement and optionally attach IPCs for this phase."
                className="max-w-3xl"
            >
                <form onSubmit={submitPhase} className="space-y-5">
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label htmlFor="phase_name">Phase name</Label>
                            <Input
                                id="phase_name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                placeholder={`Phase ${nextPhaseNo}`}
                            />
                            {errors.name && (
                                <p className="text-sm text-red-600">{errors.name}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="phase_disbursed">Client disbursed amount</Label>
                            <AmountInput
                                id="phase_disbursed"
                                value={data.disbursed_amount}
                                onValueChange={(v) => setData('disbursed_amount', v)}
                                required
                            />
                            {errors.disbursed_amount && (
                                <p className="text-sm text-red-600">{errors.disbursed_amount}</p>
                            )}
                        </div>
                    </div>

                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-semibold text-slate-900">
                                    Phase IPCs (optional)
                                </h3>
                                <p className="text-xs text-slate-500">
                                    Add compliance rules for this phase now, or leave empty and add
                                    later.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={addIpc}
                                disabled={available_rules.length === 0}
                            >
                                <Plus className="h-4 w-4" />
                                Add IPC
                            </Button>
                        </div>

                        {available_rules.length === 0 ? (
                            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                No compliance rules defined yet. Create them under Compliance Rules
                                first if you want to attach IPCs here.
                            </p>
                        ) : data.ipcs.length === 0 ? (
                            <p className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">
                                No IPCs attached yet. Click “Add IPC” to include compliance for this
                                phase.
                            </p>
                        ) : (
                            data.ipcs.map((ipc, index) => (
                                <div
                                    key={index}
                                    className="space-y-3 rounded-lg border border-slate-200 p-4"
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <h4 className="text-sm font-semibold text-slate-900">
                                            IPC-{index + 1}
                                        </h4>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => removeIpc(index)}
                                            aria-label={`Remove IPC-${index + 1}`}
                                        >
                                            <Trash2 className="h-4 w-4 text-slate-500" />
                                        </Button>
                                    </div>
                                    <ComplianceItemsEditor
                                        items={ipc.compliance_items}
                                        availableRules={available_rules}
                                        baseAmount={data.disbursed_amount || '0'}
                                        baseLabel="Phase disbursed amount"
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
                        {errors.ipcs && <p className="text-sm text-red-600">{errors.ipcs}</p>}
                    </div>

                    <DialogFormActions
                        onCancel={closePhaseDialog}
                        processing={processing}
                        submitLabel="Save Phase"
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
