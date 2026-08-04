import AppShell from '@/Components/Layout/AppShell';
import AmendRequisitionForm from '@/Components/Domain/AmendRequisitionForm';
import CashShortfallApproveDialog from '@/Components/Domain/CashShortfallApproveDialog';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PermissionDenied from '@/Components/Shared/PermissionDenied';
import { LinkButton } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { canOverrideLimits, hasPermission } from '@/lib/permissions';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import {
    ApprovalStep,
    CashAvailability,
    ListingFilters,
    PageProps,
    Paginated,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

interface RequisitionsReviewProps extends PageProps {
    approvalSteps: Paginated<ApprovalStep>;
    cashByRequisitionId?: Record<number, CashAvailability>;
    filters: ListingFilters & { requisition_id?: string };
    focusRequisitionId?: number | null;
}

export default function RequisitionsReview() {
    const { approvalSteps, cashByRequisitionId = {}, filters, auth, focusRequisitionId } =
        usePage<RequisitionsReviewProps>().props;
    const steps = approvalSteps.data ?? [];
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = steps.find((s) => s.id === selectedId);
    const requisition = selected?.requisition;
    const showOverride = canOverrideLimits(auth.user);
    const canAmend = hasPermission(auth.user, 'requisitions', 'amend');
    const cashAvailability = requisition
        ? cashByRequisitionId[requisition.id] ?? null
        : null;
    const exceedsCash = Boolean(cashAvailability?.exceeds);

    const [cashDialogOpen, setCashDialogOpen] = useState(false);
    const amendSectionRef = useRef<HTMLDivElement>(null);
    const rejectSectionRef = useRef<HTMLDivElement>(null);

    const approveForm = useForm({ action: 'approved', comment: '', override: false });
    const rejectForm = useForm({ action: 'rejected', comment: '' });

    useEffect(() => {
        const currentSteps = approvalSteps.data ?? [];
        if (focusRequisitionId) {
            const match = currentSteps.find(
                (step) => Number(step.requisition?.id ?? step.requisition_id) === focusRequisitionId,
            );
            if (match) {
                setSelectedId(match.id);
                return;
            }
        }
        setSelectedId(currentSteps.length > 0 ? currentSteps[0].id : null);
    }, [approvalSteps.current_page, approvalSteps.total, approvalSteps.data, focusRequisitionId]);

    useEffect(() => {
        setCashDialogOpen(false);
        approveForm.setData('override', false);
        // eslint-disable-next-line react-hooks/exhaustive-deps -- reset when selection changes
    }, [selectedId]);

    function submitResolve(
        form: ReturnType<typeof useForm>,
        e?: FormEvent,
    ) {
        e?.preventDefault();
        if (!selected) return;
        form.post(`/approvals/steps/${selected.id}/resolve`);
    }

    function handleApproveSubmit(e: FormEvent) {
        e.preventDefault();
        if (!selected) return;

        // Over cash: stop plain approve — approved requests cannot be amended later.
        if (exceedsCash && !approveForm.data.override) {
            setCashDialogOpen(true);
            return;
        }

        submitResolve(approveForm);
    }

    function focusSection(ref: { current: HTMLDivElement | null }) {
        setCashDialogOpen(false);
        window.requestAnimationFrame(() => {
            ref.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function approveWithOverride() {
        if (!selected) return;
        setCashDialogOpen(false);
        approveForm.transform((data) => ({ ...data, override: true }));
        approveForm.post(`/approvals/steps/${selected.id}/resolve`, {
            onFinish: () => {
                approveForm.transform((data) => data);
            },
        });
    }

    if (!hasPermission(auth.user, 'requisitions', 'approve')) {
        return (
            <AppShell title="Review Queue">
                <Head title="Review Queue" />
                <div className="flex min-h-[50vh] items-center justify-center px-4 py-10">
                    <PermissionDenied
                        message="You do not have permission to review requisitions."
                        backHref="/requisitions"
                        backLabel="Back to requisitions"
                    />
                </div>
            </AppShell>
        );
    }

    return (
        <AppShell title="Review Queue">
            <Head title="Review Queue" />
            <div className="space-y-6">
                <PageHeader
                    title="Approval Queue"
                    description="Only published requisitions appear here. Approve, amend, or reject pending requests."
                />

                <ListToolbar
                    baseUrl="/requisitions/review-queue"
                    filters={filters}
                    searchPlaceholder="Search requisition no, role, project…"
                    sortOptions={[
                        { value: 'assigned_at', label: 'Assigned date' },
                        { value: 'level', label: 'Level' },
                        { value: 'required_role', label: 'Required role' },
                    ]}
                />

                <div className="grid gap-6 lg:grid-cols-3">
                    <DataPanel title={`Pending (${approvalSteps.total})`} noPadding>
                        {steps.length === 0 ? (
                            <p className="px-6 py-12 text-center text-sm text-slate-500">
                                No approval steps awaiting action.
                            </p>
                        ) : (
                            <>
                                <ul className="divide-y divide-slate-100">
                                    {steps.map((step) => {
                                        const stepCash =
                                            cashByRequisitionId[step.requisition?.id ?? 0];
                                        return (
                                            <li key={step.id}>
                                                <button
                                                    type="button"
                                                    onClick={() => setSelectedId(step.id)}
                                                    className={`w-full px-4 py-3 text-left hover:bg-slate-50 ${
                                                        selectedId === step.id ? 'bg-blue-50' : ''
                                                    }`}
                                                >
                                                    <p className="font-mono text-sm font-medium text-slate-900">
                                                        {step.requisition?.requisition_no}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {step.requisition?.project?.name} ·{' '}
                                                        {step.required_role}
                                                    </p>
                                                    <p className="text-xs text-slate-600">
                                                        From:{' '}
                                                        {step.requisition?.requestor?.name ??
                                                            'Unknown'}
                                                    </p>
                                                    <p className="mt-1 text-sm font-medium text-slate-700">
                                                        {formatCurrency(
                                                            step.requisition?.original_amount ??
                                                                '0',
                                                        )}
                                                    </p>
                                                    {stepCash?.exceeds && (
                                                        <p className="mt-1 text-xs font-medium text-amber-700">
                                                            Above available cash
                                                        </p>
                                                    )}
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                                <PaginationLinks paginator={approvalSteps} />
                            </>
                        )}
                    </DataPanel>

                    <div className="space-y-4 lg:col-span-2">
                        {requisition && selected ? (
                            <>
                                <DataPanel title={requisition.requisition_no}>
                                    <div className="mb-4 flex flex-wrap items-center gap-3">
                                        <StatusBadge status={String(requisition.status)} />
                                        <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">
                                            Step: {selected.required_role}
                                        </span>
                                        <LinkButton
                                            href={`/requisitions/${requisition.id}`}
                                            className="ml-auto"
                                        >
                                            Full details
                                        </LinkButton>
                                    </div>
                                    <dl className="mb-4 grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <dt className="text-xs text-slate-500">Requested by</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {requisition.requestor?.name ?? 'Unknown'}
                                            </dd>
                                            {requisition.requestor?.email && (
                                                <dd className="text-xs text-slate-500">
                                                    {requisition.requestor.email}
                                                </dd>
                                            )}
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Department</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {requisition.department}
                                            </dd>
                                        </div>
                                        {(requisition.recipients?.length ?? 0) > 0 ||
                                        requisition.recipient_name ||
                                        requisition.recipient_position ? (
                                            <div className="sm:col-span-2">
                                                <dt className="text-xs text-slate-500">
                                                    On behalf of
                                                </dt>
                                                <dd className="text-sm font-medium text-slate-900">
                                                    {(requisition.recipients?.length ?? 0) > 0
                                                        ? requisition.recipients
                                                              ?.map((r) =>
                                                                  [
                                                                      r.name === '—'
                                                                          ? null
                                                                          : r.name,
                                                                      r.position_name,
                                                                  ]
                                                                      .filter(Boolean)
                                                                      .join(' · '),
                                                              )
                                                              .filter(Boolean)
                                                              .join('; ')
                                                        : `${requisition.recipient_name || '—'}${
                                                              requisition.recipient_position
                                                                  ? ` · ${requisition.recipient_position}`
                                                                  : ''
                                                          }`}
                                                </dd>
                                            </div>
                                        ) : null}
                                        <div>
                                            <dt className="text-xs text-slate-500">Project</dt>
                                            <dd className="text-sm font-medium text-slate-900">
                                                {requisition.project?.name ?? '—'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-xs text-slate-500">Resource</dt>
                                            <dd className="text-sm font-medium capitalize text-slate-900">
                                                {String(requisition.resource_type ?? '—').replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </dd>
                                        </div>
                                    </dl>
                                    <p className="text-2xl font-bold text-slate-900">
                                        {formatCurrency(requisition.original_amount)}
                                    </p>
                                    {(requisition.items ?? []).length > 0 && (
                                        <ul className="mt-3 divide-y divide-slate-100 text-sm">
                                            {(requisition.items ?? []).map((item) => (
                                                <li
                                                    key={item.id}
                                                    className="flex justify-between gap-3 py-2"
                                                >
                                                    <span className="text-slate-700">
                                                        {item.description}
                                                        {item.category?.name
                                                            ? ` · ${item.category.name}`
                                                            : ''}
                                                        {item.recipient_name &&
                                                        item.recipient_name !== '—'
                                                            ? ` · ${item.recipient_name}`
                                                            : ''}
                                                        {item.recipient_position
                                                            ? ` (${item.recipient_position})`
                                                            : ''}
                                                    </span>
                                                    <span className="shrink-0 text-slate-600">
                                                        {formatQuantity(item.quantity)} ×{' '}
                                                        {formatCurrency(item.unit_cost)}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {requisition.boq_item && (
                                        <p className="mt-2 text-sm text-green-700">
                                            BOQ available:{' '}
                                            {formatQuantity(requisition.boq_item.available_qty)}{' '}
                                            {requisition.boq_item.unit}
                                        </p>
                                    )}
                                    {cashAvailability && (
                                        <div
                                            className={`mt-4 rounded-md border px-3 py-3 text-sm ${
                                                exceedsCash
                                                    ? 'border-amber-200 bg-amber-50 text-amber-900'
                                                    : 'border-slate-200 bg-slate-50 text-slate-700'
                                            }`}
                                        >
                                            <p className="font-medium">
                                                {cashAvailability.scope === 'organization'
                                                    ? 'Organization'
                                                    : 'Project'}{' '}
                                                cash on hand:{' '}
                                                {formatCurrency(cashAvailability.cash_on_hand)}
                                            </p>
                                            <p className="mt-1 text-xs opacity-90">
                                                Available after other commitments:{' '}
                                                {formatCurrency(cashAvailability.available)}
                                            </p>
                                            {exceedsCash && (
                                                <p className="mt-2 text-xs font-medium">
                                                    This request exceeds available cash. Amend the
                                                    amount or reject — you cannot amend after
                                                    approving.
                                                </p>
                                            )}
                                        </div>
                                    )}
                                </DataPanel>

                                <DataPanel title="Approve">
                                    <form onSubmit={handleApproveSubmit} className="space-y-3">
                                        <div className="space-y-2">
                                            <Label>Comment (optional)</Label>
                                            <Input
                                                value={approveForm.data.comment}
                                                onChange={(e) =>
                                                    approveForm.setData('comment', e.target.value)
                                                }
                                            />
                                        </div>
                                        {showOverride && !exceedsCash && (
                                            <label className="flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={approveForm.data.override}
                                                    onChange={(e) =>
                                                        approveForm.setData(
                                                            'override',
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                Override BOQ / cash limits
                                            </label>
                                        )}
                                        {exceedsCash && (
                                            <p className="text-xs text-amber-700">
                                                Approve is blocked while the amount exceeds
                                                available cash. Use Amend or Reject
                                                {showOverride
                                                    ? ', or confirm override in the reminder dialog'
                                                    : ''}
                                                .
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            disabled={approveForm.processing}
                                            className="bg-green-700 hover:bg-green-800"
                                        >
                                            Approve
                                        </Button>
                                    </form>
                                </DataPanel>

                                {canAmend && (
                                    <div ref={amendSectionRef}>
                                        <DataPanel
                                            title="Amend"
                                            description="Edit line quantities and costs. Total is derived from the amended lines."
                                        >
                                            <AmendRequisitionForm
                                                key={selected.id}
                                                items={requisition.items ?? []}
                                                originalAmount={String(requisition.original_amount)}
                                                resolveUrl={`/approvals/steps/${selected.id}/resolve`}
                                                showOverride={showOverride}
                                            />
                                        </DataPanel>
                                    </div>
                                )}

                                <div ref={rejectSectionRef}>
                                    <DataPanel title="Reject">
                                        <form
                                            onSubmit={(e) => submitResolve(rejectForm, e)}
                                            className="space-y-3"
                                        >
                                            <div className="space-y-2">
                                                <Label>Rejection Reason</Label>
                                                <Input
                                                    value={rejectForm.data.comment}
                                                    onChange={(e) =>
                                                        rejectForm.setData(
                                                            'comment',
                                                            e.target.value,
                                                        )
                                                    }
                                                    required
                                                />
                                            </div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={rejectForm.processing}
                                                className="border-red-300 text-red-700 hover:bg-red-50"
                                            >
                                                Reject
                                            </Button>
                                        </form>
                                    </DataPanel>
                                </div>
                            </>
                        ) : (
                            <DataPanel>
                                <p className="py-12 text-center text-sm text-slate-500">
                                    Select an approval step from the queue.
                                </p>
                            </DataPanel>
                        )}
                    </div>
                </div>
            </div>

            {cashAvailability && (
                <CashShortfallApproveDialog
                    open={cashDialogOpen}
                    onOpenChange={setCashDialogOpen}
                    availability={cashAvailability}
                    canAmend={canAmend}
                    canOverride={showOverride}
                    overrideChecked={approveForm.data.override}
                    onOverrideChange={(checked) => approveForm.setData('override', checked)}
                    onAmend={() => focusSection(amendSectionRef)}
                    onReject={() => focusSection(rejectSectionRef)}
                    onApproveWithOverride={approveWithOverride}
                    processing={approveForm.processing}
                />
            )}
        </AppShell>
    );
}
