import AppShell from '@/Components/Layout/AppShell';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { IconLink } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { hasPermission } from '@/lib/permissions';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, Requisition, RequisitionStatus } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, ClipboardCheck } from 'lucide-react';

interface RequisitionsIndexProps extends PageProps {
    requisitions: Paginated<Requisition>;
    filters: ListingFilters & {
        status?: string;
        department?: string;
        project_id?: string;
    };
}

const statusOptions: RequisitionStatus[] = [
    'draft', 'submitted', 'under_review', 'approved', 'amended',
    'rejected', 'fulfilled', 'closed', 'cancelled',
];

function isEditableStatus(status: string): boolean {
    return status === 'draft' || status === 'rejected';
}

function pendingStepId(req: Requisition): number | null {
    const steps = req.approval_steps ?? [];
    const pending = steps.find((step) => step.status === 'pending');
    return pending?.id ?? null;
}

export default function RequisitionsIndex() {
    const { requisitions, filters, auth } = usePage<RequisitionsIndexProps>().props;
    const rows = requisitions.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');
    const canApprove = hasPermission(auth.user, 'requisitions', 'approve');
    const canFulfill = hasPermission(auth.user, 'requisitions', 'fulfill');

    return (
        <AppShell title="Requisitions">
            <Head title="Requisitions" />
            <div className="space-y-6">
                <PageHeader
                    title="Requisitions"
                    description="Drafts stay private to the author until published for approval. Approvers use Decide or the Review Queue."
                    actions={
                        <>
                            {canApprove && (
                                <Link href="/requisitions/review-queue">
                                    <Button variant="outline">Review Queue</Button>
                                </Link>
                            )}
                            {canFulfill && (
                                <Link href="/requisitions/fulfill-queue">
                                    <Button variant="outline">Fulfill Queue</Button>
                                </Link>
                            )}
                            {canCreate && (
                                <Link href="/requisitions/create">
                                    <Button>
                                        <Plus className="h-4 w-4" />
                                        New Requisition
                                    </Button>
                                </Link>
                            )}
                        </>
                    }
                />

                <ListToolbar
                    baseUrl="/requisitions"
                    filters={filters}
                    searchPlaceholder="Search requisition no, department…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date' },
                        { value: 'requisition_no', label: 'Requisition no' },
                        { value: 'department', label: 'Department' },
                        { value: 'status', label: 'Status' },
                        { value: 'original_amount', label: 'Amount' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: statusOptions.map((s) => ({
                                value: s,
                                label: s.replace(/_/g, ' '),
                            })),
                        },
                    ]}
                    textFilters={[{ key: 'department', label: 'Department', placeholder: 'Department' }]}
                />

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Requisition No</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Resource</th>
                                <th className="px-6 py-3 font-medium">Department</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Amount</th>
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-6 py-12 text-center text-slate-500">
                                        No requisitions found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((req) => {
                                    const status = String(req.status);
                                    const canDecide =
                                        canApprove &&
                                        status === 'under_review' &&
                                        pendingStepId(req) !== null &&
                                        req.requestor_id !== auth.user?.id;

                                    return (
                                        <tr key={req.id} className="hover:bg-slate-50">
                                            <td className="px-6 py-4 font-mono text-slate-900">
                                                {req.requisition_no}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">
                                                {req.project?.name ?? '—'}
                                            </td>
                                            <td className="px-6 py-4 capitalize text-slate-600">
                                                {String(req.resource_type ?? '—').replace(/_/g, ' ')}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">{req.department}</td>
                                            <td className="px-6 py-4">
                                                <StatusBadge status={status} />
                                            </td>
                                            <td className="px-6 py-4 text-right font-medium text-slate-900">
                                                {formatCurrency(
                                                    req.amended_amount ?? req.original_amount,
                                                )}
                                            </td>
                                            <td className="px-6 py-4 text-slate-600">
                                                {formatDate(req.created_at)}
                                            </td>
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex items-center justify-end gap-1">
                                                    {canDecide && (
                                                        <IconLink
                                                            href={`/requisitions/review-queue?requisition_id=${req.id}`}
                                                            icon={ClipboardCheck}
                                                            label="Decide — approve, amend, or reject"
                                                            variant="outline"
                                                        />
                                                    )}
                                                    {canUpdate &&
                                                        isEditableStatus(status) &&
                                                        req.requestor_id === auth.user?.id && (
                                                            <IconLink
                                                                href={`/requisitions/${req.id}/edit`}
                                                                icon={Pencil}
                                                                label="Edit requisition"
                                                            />
                                                        )}
                                                    <IconLink
                                                        href={`/requisitions/${req.id}`}
                                                        icon={Eye}
                                                        label="View requisition"
                                                    />
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={requisitions} />
                </div>
            </div>
        </AppShell>
    );
}
