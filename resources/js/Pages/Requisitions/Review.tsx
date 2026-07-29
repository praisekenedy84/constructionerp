import AppShell from '@/Components/Layout/AppShell';
import AmendRequisitionForm from '@/Components/Domain/AmendRequisitionForm';
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
import { ApprovalStep, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';

interface RequisitionsReviewProps extends PageProps {
    approvalSteps: Paginated<ApprovalStep>;
    filters: ListingFilters & { requisition_id?: string };
    focusRequisitionId?: number | null;
}

export default function RequisitionsReview() {
    const { approvalSteps, filters, auth, focusRequisitionId } =
        usePage<RequisitionsReviewProps>().props;
    const steps = approvalSteps.data ?? [];
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = steps.find((s) => s.id === selectedId);
    const requisition = selected?.requisition;
    const showOverride = canOverrideLimits(auth.user);

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

    const approveForm = useForm({ action: 'approved', comment: '', override: false });
    const rejectForm = useForm({ action: 'rejected', comment: '' });

    function submitResolve(
        form: ReturnType<typeof useForm>,
        e: FormEvent,
    ) {
        e.preventDefault();
        if (!selected) return;
        form.post(`/approvals/steps/${selected.id}/resolve`);
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
                                    {steps.map((step) => (
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
                                                    {step.requisition?.requestor?.name ?? 'Unknown'}
                                                </p>
                                                <p className="mt-1 text-sm font-medium text-slate-700">
                                                    {formatCurrency(
                                                        step.requisition?.original_amount ?? '0',
                                                    )}
                                                </p>
                                            </button>
                                        </li>
                                    ))}
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
                                        <LinkButton href={`/requisitions/${requisition.id}`} className="ml-auto">
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
                                </DataPanel>

                                <DataPanel title="Approve">
                                    <form
                                        onSubmit={(e) => submitResolve(approveForm, e)}
                                        className="space-y-3"
                                    >
                                        <div className="space-y-2">
                                            <Label>Comment (optional)</Label>
                                            <Input
                                                value={approveForm.data.comment}
                                                onChange={(e) =>
                                                    approveForm.setData('comment', e.target.value)
                                                }
                                            />
                                        </div>
                                        {showOverride && (
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
                                        <Button
                                            type="submit"
                                            disabled={approveForm.processing}
                                            className="bg-green-700 hover:bg-green-800"
                                        >
                                            Approve
                                        </Button>
                                    </form>
                                </DataPanel>

                                {hasPermission(auth.user, 'requisitions', 'amend') && (
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
                                )}

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
                                                    rejectForm.setData('comment', e.target.value)
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
        </AppShell>
    );
}
