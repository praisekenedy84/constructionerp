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
import { PaymentMethodSelect } from '@/Components/ui/payment-method-select';
import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Project, ProjectPhase, Sale } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Pencil, Plus, ShoppingCart, Trash2, TriangleAlert } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Tab = 'overview' | 'boq' | 'budget' | 'requisitions' | 'finance' | 'reports';

interface PhaseIpcForm {
    compliance_items: ComplianceItemForm[];
}

interface ProjectComplianceItemRow {
    id: number;
    compliance_rule_id: number;
    rule_name: string | null;
    calculation_type: 'rate_percent' | 'fixed_amount';
    rate: string | null;
    fixed_amount: string | null;
    amount: string;
    allocation_level: 'contract' | 'phase';
    phase_id: number | null;
    phase_label: string | null;
    valuation_id: number | null;
    attached_at: string | null;
    migrated_at: string | null;
    events: Array<{
        event_type: string;
        phase_id: number | null;
        created_at: string | null;
        meta: Record<string, unknown> | null;
    }>;
}

interface ContractSummary {
    contract_amount: string;
    compliance_total: string;
    remaining_contract_value: string;
    phase_allocated: string;
    unallocated_contract_value: string;
    has_phases: boolean;
}

interface ManagerAccountOption {
    id: number;
    name: string;
    bank_name: string | null;
    balance: string;
}

interface RetentionCumulative {
    held: string;
    released: string;
    receivable: string;
    forfeited?: string;
}

interface ProjectsShowProps extends PageProps {
    project: Project;
    sales?: Sale[];
    phases: ProjectPhase[];
    retention_cumulative?: RetentionCumulative;
    retention_receivables?: Sale[];
    manager_accounts?: ManagerAccountOption[];
    compliance_items?: ProjectComplianceItemRow[];
    contract_summary?: ContractSummary;
    available_rules: AvailableComplianceRule[];
    project_staff?: Array<{
        id: number;
        name: string;
        phone: string;
        email?: string | null;
        status: string;
    }>;
    available_recipients?: Array<{
        id: number;
        name: string;
        phone: string;
        email?: string | null;
        status: string;
    }>;
    tab?: Tab;
}

function halfAmounts(held: string | number): { released: string; fee: string } {
    const cents = Math.round(Number(held) * 100);
    const releasedCents = Math.floor(cents / 2);
    const feeCents = cents - releasedCents;
    return {
        released: (releasedCents / 100).toFixed(2),
        fee: (feeCents / 100).toFixed(2),
    };
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
        sales = [],
        phases,
        retention_cumulative,
        retention_receivables = [],
        manager_accounts = [],
        compliance_items = [],
        contract_summary,
        available_rules = [],
        project_staff = [],
        available_recipients = [],
        tab = 'overview',
        auth,
        errors: pageErrors = {},
    } = usePage<ProjectsShowProps & { errors?: Record<string, string> }>().props;
    const canUpdate = hasPermission(auth.user, 'projects', 'update');
    const canDelete = hasPermission(auth.user, 'projects', 'delete-soft');
    const canReadSales = hasPermission(auth.user, 'sales', 'read');
    const canCollectSales = hasPermission(auth.user, 'sales', 'collect');
    const nextPhaseNo = (phases[phases.length - 1]?.sequence_no ?? 0) + 1;
    const hasPhases = contract_summary?.has_phases ?? phases.length > 0;
    const contractItems = compliance_items.filter((item) => item.allocation_level === 'contract');
    const migratedItems = compliance_items.filter((item) => item.allocation_level === 'phase');
    const cumulative = retention_cumulative ?? {
        held: phases
            .reduce((sum, phase) => sum + Number(phase.retention_held_amount), 0)
            .toFixed(2),
        released: phases
            .reduce((sum, phase) => sum + Number(phase.retention_released_amount), 0)
            .toFixed(2),
        receivable: phases
            .reduce((sum, phase) => sum + Number(phase.retention_receivable_amount ?? 0), 0)
            .toFixed(2),
        forfeited: phases
            .reduce((sum, phase) => sum + Number(phase.retention_forfeited_amount), 0)
            .toFixed(2),
    };
    const collectableRetentionReceivables = retention_receivables.filter(
        (sale) => sale.can_collect && Number(sale.outstanding_amount) > 0,
    );
    const retentionOutstandingTotal = collectableRetentionReceivables
        .reduce((sum, sale) => sum + Number(sale.outstanding_amount), 0)
        .toFixed(2);

    const [phaseDialogOpen, setPhaseDialogOpen] = useState(false);
    const [complianceDialogOpen, setComplianceDialogOpen] = useState(false);
    const [releaseDialogOpen, setReleaseDialogOpen] = useState(false);
    const [collectDialogOpen, setCollectDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const releaseForm = useForm({ money_account_id: '' });
    const deleteForm = useForm({ confirmation_code: '' });
    const collectForm = useForm({
        sale_id: '',
        amount: '',
        money_account_id: '',
        method: '',
        reference_no: '',
        notes: '',
        occurred_at: '',
    });
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

    const complianceForm = useForm<{ compliance_items: ComplianceItemForm[] }>({
        compliance_items: [emptyComplianceItem()],
    });

    const staffForm = useForm<{ recipient_ids: number[] }>({
        recipient_ids: project_staff.map((recipient) => recipient.id),
    });

    function saveProjectStaff(e: FormEvent) {
        e.preventDefault();
        staffForm.post(`/projects/${project.id}/recipients`);
    }

    function openDeleteDialog() {
        deleteForm.clearErrors();
        deleteForm.setData('confirmation_code', '');
        setDeleteDialogOpen(true);
    }

    function closeDeleteDialog() {
        setDeleteDialogOpen(false);
        deleteForm.reset();
        deleteForm.clearErrors();
    }

    function submitDelete(e: FormEvent) {
        e.preventDefault();
        deleteForm.delete(`/projects/${project.id}`, {
            onSuccess: () => {
                closeDeleteDialog();
            },
        });
    }

    function markProjectAsLoss() {
        if (
            !confirm(
                `Mark project "${project.code}" as a LOSS? The pending deficit will become a negative receivable against a company account.`,
            )
        ) {
            return;
        }

        router.post(`/projects/${project.id}/mark-loss`);
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

    function openComplianceDialog() {
        complianceForm.clearErrors();
        complianceForm.reset();
        complianceForm.setData('compliance_items', [emptyComplianceItem()]);
        setComplianceDialogOpen(true);
    }

    function closeComplianceDialog() {
        if (!confirmDiscardIfDirty(complianceForm.isDirty)) {
            return;
        }
        setComplianceDialogOpen(false);
        complianceForm.reset();
        complianceForm.clearErrors();
    }

    function openReleaseDialog() {
        releaseForm.clearErrors();
        releaseForm.setData(
            'money_account_id',
            manager_accounts.length === 1 ? String(manager_accounts[0].id) : '',
        );
        setReleaseDialogOpen(true);
    }

    function closeReleaseDialog() {
        setReleaseDialogOpen(false);
        releaseForm.reset();
        releaseForm.clearErrors();
    }

    function submitRelease(e: FormEvent) {
        e.preventDefault();
        releaseForm.post(`/projects/${project.id}/retention/release`, {
            onSuccess: () => {
                closeReleaseDialog();
            },
        });
    }

    const releasePreview = halfAmounts(cumulative.held);

    function openCollectDialog() {
        const first = collectableRetentionReceivables[0];
        collectForm.clearErrors();
        collectForm.setData({
            sale_id: first ? String(first.id) : '',
            amount: first?.outstanding_amount ?? '',
            money_account_id:
                manager_accounts.length === 1 ? String(manager_accounts[0].id) : '',
            method: '',
            reference_no: '',
            notes: '',
            occurred_at: '',
        });
        setCollectDialogOpen(true);
    }

    function closeCollectDialog() {
        if (!confirmDiscardIfDirty(collectForm.isDirty)) {
            return;
        }
        setCollectDialogOpen(false);
        collectForm.reset();
        collectForm.clearErrors();
    }

    function submitCollect(e: FormEvent) {
        e.preventDefault();
        if (!collectForm.data.sale_id) {
            return;
        }
        collectForm.post(`/sales/${collectForm.data.sale_id}/collect`, {
            onSuccess: () => {
                setCollectDialogOpen(false);
                collectForm.reset();
                collectForm.clearErrors();
            },
        });
    }

    function selectCollectSale(saleId: string) {
        const sale = collectableRetentionReceivables.find((item) => String(item.id) === saleId);
        collectForm.setData({
            ...collectForm.data,
            sale_id: saleId,
            amount: sale?.outstanding_amount ?? '',
        });
    }

    function submitCompliance(e: FormEvent) {
        e.preventDefault();
        complianceForm.post(`/projects/${project.id}/compliance`, {
            onSuccess: () => {
                setComplianceDialogOpen(false);
                complianceForm.reset();
                complianceForm.clearErrors();
            },
        });
    }

    function removeComplianceItem(itemId: number, ruleName: string | null) {
        if (!confirm(`Remove contract compliance "${ruleName ?? 'item'}"?`)) {
            return;
        }
        router.delete(`/projects/${project.id}/compliance/${itemId}`);
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
                    description={[
                        project.code,
                        project.client,
                        project.customer?.contact,
                        project.customer?.tax_information
                            ? `TIN ${project.customer.tax_information}`
                            : null,
                        project.location,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
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
                            {canUpdate && project.can_mark_loss && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="border-red-200 text-red-700 hover:bg-red-50"
                                    onClick={markProjectAsLoss}
                                >
                                    <TriangleAlert className="h-4 w-4" />
                                    Mark as Loss
                                </Button>
                            )}
                            {canDelete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="border-red-200 text-red-700 hover:bg-red-50"
                                    onClick={openDeleteDialog}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Delete
                                </Button>
                            )}
                            <Link href={`/projects/${project.id}/valuations`}>
                                <Button variant="outline" size="sm">
                                    IPCs
                                </Button>
                            </Link>
                            {canReadSales && sales.length > 0 && (
                                <Link href="/sales">
                                    <Button variant="outline" size="sm">
                                        <ShoppingCart className="h-4 w-4" />
                                        Sales
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <DataPanel title={hasPhases ? 'Net Sales Received' : 'Remaining Contract Value'}>
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(
                                hasPhases
                                    ? project.net_budget
                                    : (contract_summary?.remaining_contract_value ??
                                          project.remaining_contract_value ??
                                          project.net_budget),
                            )}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            {hasPhases
                                ? 'Sum of phase budgets after deductions and retention actions'
                                : 'Contract value minus contract-level compliance (phases not started)'}
                        </p>
                    </DataPanel>
                    <DataPanel title="Net Operating Profit">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(project.remaining_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Contract Amount">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.contract_amount)}
                        </p>
                        {(contract_summary?.compliance_total ??
                            project.contract_compliance_total) &&
                            Number(
                                contract_summary?.compliance_total ??
                                    project.contract_compliance_total,
                            ) > 0 && (
                                <p className="mt-1 text-xs text-slate-500">
                                    Contract compliance:{' '}
                                    {formatCurrency(
                                        contract_summary?.compliance_total ??
                                            project.contract_compliance_total,
                                    )}
                                </p>
                            )}
                    </DataPanel>
                    <DataPanel title="Budget Utilization">
                        <p className="text-2xl font-bold text-blue-700">
                            {formatPercent(project.utilization_percentage)}
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Includes compliance deductions and subsequent budget charges
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
                    {pageErrors.loss && (
                        <p className="mb-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {pageErrors.loss}
                        </p>
                    )}
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-xs text-slate-500">Status</dt>
                            <dd className="mt-1">
                                <StatusBadge status={String(project.status)} />
                            </dd>
                        </div>
                        {(Number(project.pending_deficit) > 0 || Number(project.live_remaining) < 0) && (
                            <div>
                                <dt className="text-xs text-slate-500">Pending deficit</dt>
                                <dd className="mt-1 text-sm font-semibold text-red-700">
                                    {formatCurrency(
                                        Number(project.pending_deficit) > 0
                                            ? project.pending_deficit
                                            : Math.abs(Number(project.live_remaining ?? 0)).toFixed(2),
                                    )}
                                </dd>
                            </div>
                        )}
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

                <DataPanel title="Project Staff / Recipients" noPadding>
                    <div className="border-b border-slate-200 p-4">
                        <p className="text-sm text-slate-600">
                            Reference list of recipients used on this project. Recipients can belong
                            to multiple projects. This list does not change project operations.
                        </p>
                    </div>
                    {canUpdate ? (
                        <form onSubmit={saveProjectStaff} className="space-y-4 p-4">
                            {available_recipients.length === 0 ? (
                                <p className="text-sm text-slate-500">
                                    No recipients registered yet. Add them under Recipients first.
                                </p>
                            ) : (
                                <div className="max-h-64 space-y-2 overflow-y-auto rounded-md border border-slate-200 p-3">
                                    {available_recipients.map((recipient) => {
                                        const checked = staffForm.data.recipient_ids.includes(
                                            recipient.id,
                                        );
                                        return (
                                            <label
                                                key={recipient.id}
                                                className="flex items-start gap-3 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    className="mt-1"
                                                    checked={checked}
                                                    onChange={(e) => {
                                                        const next = e.target.checked
                                                            ? [
                                                                  ...staffForm.data.recipient_ids,
                                                                  recipient.id,
                                                              ]
                                                            : staffForm.data.recipient_ids.filter(
                                                                  (id) => id !== recipient.id,
                                                              );
                                                        staffForm.setData('recipient_ids', next);
                                                    }}
                                                />
                                                <span>
                                                    <span className="font-medium">
                                                        {recipient.name}
                                                    </span>
                                                    <span className="block text-xs text-slate-500">
                                                        {recipient.phone}
                                                        {recipient.email
                                                            ? ` · ${recipient.email}`
                                                            : ''}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </div>
                            )}
                            <Button type="submit" size="sm" disabled={staffForm.processing}>
                                {staffForm.processing ? 'Saving…' : 'Save Staff List'}
                            </Button>
                        </form>
                    ) : project_staff.length === 0 ? (
                        <p className="px-6 py-8 text-center text-sm text-slate-500">
                            No project staff linked yet.
                        </p>
                    ) : (
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-6 py-3 font-medium">Name</th>
                                    <th className="px-6 py-3 font-medium">Phone</th>
                                    <th className="px-6 py-3 font-medium">Email</th>
                                    <th className="px-6 py-3 font-medium">Status</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {project_staff.map((recipient) => (
                                    <tr key={recipient.id}>
                                        <td className="px-6 py-3 font-medium">{recipient.name}</td>
                                        <td className="px-6 py-3 text-slate-600">
                                            {recipient.phone}
                                        </td>
                                        <td className="px-6 py-3 text-slate-600">
                                            {recipient.email ?? '—'}
                                        </td>
                                        <td className="px-6 py-3 capitalize text-slate-600">
                                            {recipient.status}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </DataPanel>

                <DataPanel title="Contract Compliance" noPadding>
                    <div className="flex items-center justify-between gap-3 border-b border-slate-200 p-4">
                        <p className="text-sm text-slate-600">
                            Compliance is tracked against contract value first. When Phase 1 is
                            started, these obligations move to that phase (same amounts, no
                            duplication).
                        </p>
                        {canUpdate && !hasPhases && (
                            <Button type="button" size="sm" onClick={openComplianceDialog}>
                                <Plus className="h-4 w-4" />
                                Add Compliance
                            </Button>
                        )}
                    </div>
                    {hasPhases && (
                        <p className="border-b border-slate-100 bg-slate-50 px-4 py-2 text-xs text-slate-500">
                            Phases have started — new compliance is added on the phase via IPCs.
                            Contract items above were migrated to Phase 1 (or remain for history).
                        </p>
                    )}
                    {(pageErrors.compliance || complianceForm.errors.compliance) && (
                        <p className="border-b border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                            {pageErrors.compliance || complianceForm.errors.compliance}
                        </p>
                    )}
                    <div className="border-b border-slate-100 px-4 py-3 text-sm">
                        <dl className="flex flex-wrap gap-x-6 gap-y-1 text-slate-600">
                            <div>
                                Contract:{' '}
                                <span className="font-medium text-slate-900">
                                    {formatCurrency(
                                        contract_summary?.contract_amount ??
                                            project.contract_amount,
                                    )}
                                </span>
                            </div>
                            <div>
                                Compliance:{' '}
                                <span className="font-medium text-red-700">
                                    −
                                    {formatCurrency(
                                        contract_summary?.compliance_total ?? '0',
                                    )}
                                </span>
                            </div>
                            <div>
                                Remaining:{' '}
                                <span className="font-medium text-slate-900">
                                    {formatCurrency(
                                        contract_summary?.remaining_contract_value ??
                                            project.remaining_contract_value,
                                    )}
                                </span>
                            </div>
                            {hasPhases && (
                                <div>
                                    Unallocated after phases:{' '}
                                    <span className="font-medium text-slate-900">
                                        {formatCurrency(
                                            contract_summary?.unallocated_contract_value,
                                        )}
                                    </span>
                                </div>
                            )}
                        </dl>
                    </div>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Rule</th>
                                <th className="px-6 py-3 font-medium">Basis</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {compliance_items.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        {hasPhases
                                            ? 'No contract compliance on record. Add new obligations on the phase via IPCs.'
                                            : 'No compliance yet. Attach obligations to the contract value — a phase is not required.'}
                                    </td>
                                </tr>
                            ) : (
                                <>
                                    {contractItems.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-6 py-3 font-medium text-slate-900">
                                                {item.rule_name}
                                            </td>
                                            <td className="px-6 py-3 text-slate-600">
                                                {item.calculation_type === 'rate_percent'
                                                    ? `${item.rate}% of contract`
                                                    : 'Fixed amount'}
                                            </td>
                                            <td className="px-6 py-3 text-right font-medium">
                                                {formatCurrency(item.amount)}
                                            </td>
                                            <td className="px-6 py-3 text-slate-600">
                                                On contract
                                                {item.attached_at && (
                                                    <div className="text-xs text-slate-400">
                                                        Attached {formatDate(item.attached_at)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-3 text-right">
                                                {canUpdate && !hasPhases && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            removeComplianceItem(
                                                                item.id,
                                                                item.rule_name,
                                                            )
                                                        }
                                                    >
                                                        <Trash2 className="h-4 w-4 text-slate-500" />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                    {migratedItems.map((item) => (
                                        <tr key={item.id} className="bg-slate-50/60">
                                            <td className="px-6 py-3 font-medium text-slate-900">
                                                {item.rule_name}
                                            </td>
                                            <td className="px-6 py-3 text-slate-600">
                                                {item.calculation_type === 'rate_percent'
                                                    ? `${item.rate}% of contract (at attach)`
                                                    : 'Fixed amount'}
                                            </td>
                                            <td className="px-6 py-3 text-right font-medium">
                                                {formatCurrency(item.amount)}
                                            </td>
                                            <td className="px-6 py-3 text-slate-600">
                                                Migrated to {item.phase_label ?? 'phase'}
                                                {item.migrated_at && (
                                                    <div className="text-xs text-slate-400">
                                                        {formatDate(item.migrated_at)}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="px-6 py-3 text-right text-xs text-slate-400">
                                                {item.valuation_id ? (
                                                    <Link
                                                        href={`/projects/${project.id}/valuations/${item.valuation_id}`}
                                                        className="text-blue-700 hover:underline"
                                                    >
                                                        View IPC
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </>
                            )}
                        </tbody>
                    </table>
                </DataPanel>

                <DataPanel title="Phases & Retention" noPadding>
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
                        <p className="text-sm text-slate-600">
                            Retention is recorded on each phase’s IPCs and held until project-end
                            release (not as an expense). Releasing once splits cumulative held
                            retention 50/50: half to a company account / budget, half as a
                            collectible receivable.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {canUpdate && Number(cumulative.held) > 0 && (
                                <Button type="button" size="sm" onClick={openReleaseDialog}>
                                    Release cumulative retention
                                </Button>
                            )}
                            {canCollectSales && collectableRetentionReceivables.length > 0 && (
                                <Button type="button" size="sm" variant="outline" onClick={openCollectDialog}>
                                    Collect retention receivable
                                </Button>
                            )}
                            {canUpdate && (
                                <Button type="button" size="sm" variant="outline" onClick={openPhaseDialog}>
                                    <Plus className="h-4 w-4" />
                                    Add Phase
                                </Button>
                            )}
                        </div>
                    </div>
                    {phases.length > 0 && (
                        <div className="grid gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 sm:grid-cols-3">
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Cumulative held
                                </p>
                                <p className="mt-1 text-sm font-semibold text-amber-700">
                                    {formatCurrency(cumulative.held)}
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Cumulative released
                                </p>
                                <p className="mt-1 text-sm font-semibold text-green-700">
                                    {formatCurrency(cumulative.released)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    50% deposited to company account + budget
                                </p>
                            </div>
                            <div>
                                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                    Cumulative receivable
                                </p>
                                <p className="mt-1 text-sm font-semibold text-blue-700">
                                    {formatCurrency(cumulative.receivable)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    50% recorded as retention receivable
                                    {Number(retentionOutstandingTotal) > 0
                                        ? ` · ${formatCurrency(retentionOutstandingTotal)} outstanding`
                                        : ''}
                                </p>
                            </div>
                        </div>
                    )}
                    {errors.phase && (
                        <p className="border-b border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                            {errors.phase}
                        </p>
                    )}
                    {pageErrors.phase && (
                        <p className="border-b border-red-100 bg-red-50 px-4 py-2 text-sm text-red-700">
                            {pageErrors.phase}
                        </p>
                    )}
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Phase</th>
                                <th className="px-6 py-3 text-right font-medium">Disbursed</th>
                                <th className="px-6 py-3 text-right font-medium">IPC total</th>
                                <th className="px-6 py-3 text-right font-medium">Held</th>
                                <th className="px-6 py-3 text-right font-medium">Released</th>
                                <th className="px-6 py-3 text-right font-medium">Receivable</th>
                                <th className="px-6 py-3 text-right font-medium">Forfeited</th>
                                <th className="px-6 py-3 text-right font-medium">Phase budget</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {phases.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={9}
                                        className="px-6 py-10 text-center text-slate-500"
                                    >
                                        No phases yet. Add a phase when the client disburses
                                        payment — this can be after compliance or once work has
                                        started.
                                    </td>
                                </tr>
                            ) : (
                                phases.map((phase) => (
                                    <tr key={phase.id}>
                                        <td className="px-6 py-3">
                                            <Link
                                                href={`/projects/${project.id}/phases/${phase.id}`}
                                                className="font-medium text-blue-700 hover:underline"
                                            >
                                                Phase {phase.sequence_no}: {phase.name}
                                            </Link>
                                            <div className="text-xs text-slate-500 capitalize">
                                                {phase.status}
                                            </div>
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            {formatCurrency(phase.disbursed_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <div className="font-medium text-red-700">
                                                −
                                                {formatCurrency(
                                                    phase.valuations_sum_total_deductions ?? '0',
                                                )}
                                            </div>
                                            <div className="text-xs text-slate-500">
                                                {phase.valuations_count ?? 0} IPC
                                                {(phase.valuations_count ?? 0) === 1 ? '' : 's'}
                                            </div>
                                        </td>
                                        <td className="px-6 py-3 text-right text-amber-700">
                                            {formatCurrency(phase.retention_held_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-green-700">
                                            {formatCurrency(phase.retention_released_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right text-blue-700">
                                            {formatCurrency(phase.retention_receivable_amount ?? '0')}
                                        </td>
                                        <td className="px-6 py-3 text-right text-red-700">
                                            {formatCurrency(phase.retention_forfeited_amount)}
                                        </td>
                                        <td className="px-6 py-3 text-right font-semibold">
                                            {formatCurrency(phase.phase_net_budget)}
                                        </td>
                                        <td className="px-6 py-3 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link
                                                    href={`/projects/${project.id}/phases/${phase.id}`}
                                                >
                                                    <Button variant="outline" size="sm">
                                                        View
                                                    </Button>
                                                </Link>
                                                {canUpdate && phase.status !== 'closed' && (
                                                    <Button
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => {
                                                            if (
                                                                !confirm(
                                                                    `Close Phase ${phase.sequence_no}: ${phase.name}? Surplus becomes a receivable; a deficit carries to the next phase.`,
                                                                )
                                                            ) {
                                                                return;
                                                            }
                                                            router.post(
                                                                `/projects/${project.id}/phases/${phase.id}/close`,
                                                            );
                                                        }}
                                                    >
                                                        Close
                                                    </Button>
                                                )}
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
                description="Record the next client disbursement. If this is Phase 1, any contract-level compliance moves here automatically."
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
                            {nextPhaseNo === 1 &&
                                Number(contract_summary?.compliance_total ?? 0) > 0 && (
                                    <p className="text-xs text-slate-500">
                                        Phase 1 must be at least{' '}
                                        {formatCurrency(contract_summary?.compliance_total)} so
                                        contract compliance can move onto this disbursement.
                                    </p>
                                )}
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

            <Dialog
                open={complianceDialogOpen}
                onOpenChange={(next) => (next ? undefined : closeComplianceDialog())}
                title="Add Contract Compliance"
                description="Obligations are calculated against the project contract value. They move to Phase 1 when that phase is started."
                className="max-w-3xl"
            >
                <form onSubmit={submitCompliance} className="space-y-5">
                    {available_rules.length === 0 ? (
                        <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                            No compliance rules defined yet. Create them under{' '}
                            <Link href="/projects/compliance-rules" className="underline">
                                Compliance Rules
                            </Link>{' '}
                            first.
                        </p>
                    ) : (
                        <ComplianceItemsEditor
                            items={complianceForm.data.compliance_items}
                            availableRules={available_rules}
                            baseAmount={String(project.contract_amount)}
                            baseLabel="Contract amount"
                            ipcLabel="Contract compliance"
                            summaryMode="ipc-only"
                            hideHeader
                            errors={complianceForm.errors}
                            onChange={(items) =>
                                complianceForm.setData('compliance_items', items)
                            }
                        />
                    )}

                    <DialogFormActions
                        onCancel={closeComplianceDialog}
                        processing={complianceForm.processing}
                        submitLabel="Save Compliance"
                        processingLabel="Saving…"
                        disabled={available_rules.length === 0}
                    />
                </form>
            </Dialog>

            <Dialog
                open={releaseDialogOpen}
                onOpenChange={(next) => (next ? undefined : closeReleaseDialog())}
                title="Release cumulative retention"
                description="Splits all held retention across every phase 50/50 once: half deposits to a company account and returns to budget; half becomes a collectible retention receivable (not an expense)."
            >
                <form onSubmit={submitRelease} className="space-y-5">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                                Cumulative held
                            </p>
                            <p className="mt-1 font-semibold">
                                {formatCurrency(cumulative.held)}
                            </p>
                        </div>
                        <div className="rounded-lg border border-green-200 bg-green-50 p-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-green-700">
                                To account (50%)
                            </p>
                            <p className="mt-1 font-semibold text-green-800">
                                {formatCurrency(releasePreview.released)}
                            </p>
                        </div>
                        <div className="rounded-lg border border-blue-200 bg-blue-50 p-3">
                            <p className="text-xs font-medium uppercase tracking-wide text-blue-700">
                                Receivable (50%)
                            </p>
                            <p className="mt-1 font-semibold text-blue-800">
                                {formatCurrency(releasePreview.fee)}
                            </p>
                        </div>
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="release_money_account_id">Company account</Label>
                        {manager_accounts.length === 0 ? (
                            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                No active company accounts. Create one under Finance → Accounts
                                before releasing retention.
                            </p>
                        ) : (
                            <select
                                id="release_money_account_id"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={releaseForm.data.money_account_id}
                                onChange={(e) =>
                                    releaseForm.setData('money_account_id', e.target.value)
                                }
                                required
                            >
                                <option value="">Select account…</option>
                                {manager_accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                        {account.bank_name ? ` (${account.bank_name})` : ''} —{' '}
                                        {formatCurrency(account.balance)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {releaseForm.errors.money_account_id && (
                            <p className="text-sm text-red-600">
                                {releaseForm.errors.money_account_id}
                            </p>
                        )}
                    </div>

                    <DialogFormActions
                        onCancel={closeReleaseDialog}
                        processing={releaseForm.processing}
                        submitLabel="Release cumulative 50/50"
                        processingLabel="Releasing…"
                        disabled={manager_accounts.length === 0 || Number(cumulative.held) <= 0}
                    />
                </form>
            </Dialog>

            <Dialog
                open={collectDialogOpen}
                onOpenChange={(next) => (next ? undefined : closeCollectDialog())}
                title="Collect retention receivable"
                description={`Outstanding retention receivable: ${formatCurrency(retentionOutstandingTotal)}`}
            >
                <form onSubmit={submitCollect} className="space-y-4">
                    {collectableRetentionReceivables.length > 1 && (
                        <div className="space-y-2">
                            <Label htmlFor="retention_collect_sale_id">Retention sale</Label>
                            <select
                                id="retention_collect_sale_id"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={collectForm.data.sale_id}
                                onChange={(e) => selectCollectSale(e.target.value)}
                                required
                            >
                                <option value="">Select receivable…</option>
                                {collectableRetentionReceivables.map((sale) => (
                                    <option key={sale.id} value={sale.id}>
                                        {sale.sale_code} — outstanding{' '}
                                        {formatCurrency(sale.outstanding_amount)}
                                    </option>
                                ))}
                            </select>
                        </div>
                    )}

                    <div className="space-y-2">
                        <Label htmlFor="retention_collect_account_id">Company account</Label>
                        {manager_accounts.length === 0 ? (
                            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                                No active company accounts. Create one under Finance → Accounts
                                before collecting.
                            </p>
                        ) : (
                            <select
                                id="retention_collect_account_id"
                                className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-sm"
                                value={collectForm.data.money_account_id}
                                onChange={(e) =>
                                    collectForm.setData('money_account_id', e.target.value)
                                }
                                required
                            >
                                <option value="">Select account…</option>
                                {manager_accounts.map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                        {account.bank_name ? ` (${account.bank_name})` : ''} —{' '}
                                        {formatCurrency(account.balance)}
                                    </option>
                                ))}
                            </select>
                        )}
                        {collectForm.errors.money_account_id && (
                            <p className="text-sm text-red-600">
                                {collectForm.errors.money_account_id}
                            </p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="retention_collect_amount">Amount</Label>
                        <AmountInput
                            id="retention_collect_amount"
                            value={collectForm.data.amount}
                            onValueChange={(v) => collectForm.setData('amount', v)}
                            required
                        />
                        {collectForm.errors.amount && (
                            <p className="text-sm text-red-600">{collectForm.errors.amount}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="retention_collect_method">Method</Label>
                        <PaymentMethodSelect
                            id="retention_collect_method"
                            optional
                            value={collectForm.data.method}
                            onChange={(e) => collectForm.setData('method', e.target.value)}
                        />
                        {collectForm.errors.method && (
                            <p className="text-sm text-red-600">{collectForm.errors.method}</p>
                        )}
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="retention_collect_reference">Reference</Label>
                        <Input
                            id="retention_collect_reference"
                            value={collectForm.data.reference_no}
                            onChange={(e) =>
                                collectForm.setData('reference_no', e.target.value)
                            }
                        />
                    </div>

                    <div className="space-y-2">
                        <Label htmlFor="retention_collect_notes">Notes</Label>
                        <Input
                            id="retention_collect_notes"
                            value={collectForm.data.notes}
                            onChange={(e) => collectForm.setData('notes', e.target.value)}
                        />
                    </div>

                    <DialogFormActions
                        onCancel={closeCollectDialog}
                        processing={collectForm.processing}
                        submitLabel="Collect receivable"
                        processingLabel="Collecting…"
                        disabled={
                            manager_accounts.length === 0 ||
                            collectableRetentionReceivables.length === 0 ||
                            !collectForm.data.sale_id
                        }
                    />
                </form>
            </Dialog>

            <Dialog
                open={deleteDialogOpen}
                onOpenChange={(next) => (next ? undefined : closeDeleteDialog())}
                title="Delete project permanently"
                description="This cannot be undone. All project data is removed (phases, BOQ, sales, requisitions, IPCs, and related records). Finance disbursements return to accountant cash on hand; company account collections and retention deposits for this project are reversed."
            >
                <form onSubmit={submitDelete} className="space-y-5">
                    <p className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        Type the project code{' '}
                        <span className="font-mono font-semibold">{project.code}</span> to confirm.
                    </p>

                    <div className="space-y-2">
                        <Label htmlFor="delete_confirmation_code">Project code</Label>
                        <Input
                            id="delete_confirmation_code"
                            value={deleteForm.data.confirmation_code}
                            onChange={(e) =>
                                deleteForm.setData('confirmation_code', e.target.value)
                            }
                            autoComplete="off"
                            placeholder={project.code}
                            required
                        />
                        {deleteForm.errors.confirmation_code && (
                            <p className="text-sm text-red-600">
                                {deleteForm.errors.confirmation_code}
                            </p>
                        )}
                    </div>

                    <DialogFormActions
                        onCancel={closeDeleteDialog}
                        processing={deleteForm.processing}
                        submitLabel="Delete permanently"
                        processingLabel="Deleting…"
                        disabled={
                            deleteForm.data.confirmation_code.trim() !== project.code
                        }
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
