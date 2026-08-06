import AppShell from '@/Components/Layout/AppShell';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { IconLink } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { hasPermission } from '@/lib/permissions';
import { formatCurrency, formatDate, formatQuantity } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, Project, RequisitionStatus } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Download, Eye, Plus } from 'lucide-react';

interface RegisterRow {
    id: number;
    requisition_id: number;
    date: string | null;
    requested_by: string;
    recipient_name: string;
    recipient_position: string;
    requisition_no: string;
    sn: number;
    department: string;
    description: string;
    category: string;
    project_code: string;
    project_name: string;
    unit: string;
    quantity: string;
    rate: string;
    amount: string;
    status: string;
    paid: string;
    pending: string;
}

interface RegisterSummary {
    total_requested: string;
    total_paid: string;
    total_pending: string;
    requested_pct: number;
    paid_pct: number;
    pending_pct: number;
    line_count: number;
}

interface FilterOptions {
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
    categories: Array<{ id: number; name: string; is_active?: boolean }>;
    requestors: Array<{ id: number; name: string }>;
    recipients?: Array<{ id: number; name: string; phone?: string; status?: string }>;
    clients?: string[];
}

interface RequisitionsIndexProps extends PageProps {
    rows: Paginated<RegisterRow>;
    summary: RegisterSummary;
    filterOptions: FilterOptions;
    filters: ListingFilters & {
        status?: string;
        department?: string;
        project_id?: string;
        category_id?: string;
        requestor_id?: string;
        recipient_id?: string;
        client?: string;
        approval_status?: string;
    };
}

const statusOptions: RequisitionStatus[] = [
    'draft',
    'submitted',
    'under_review',
    'approved',
    'amended',
    'rejected',
    'partially_fulfilled',
    'fulfilled',
    'closed',
    'cancelled',
];

function pctLabel(value: number): string {
    return `${(value * 100).toFixed(1)}%`;
}

function exportHref(filters: Record<string, string | undefined>): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    const query = params.toString();
    return query ? `/requisitions/export?${query}` : '/requisitions/export';
}

export default function RequisitionsIndex() {
    const { rows, summary, filterOptions, filters, auth, uiSettings } =
        usePage<RequisitionsIndexProps>().props;
    const lines = rows.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const companyName = uiSettings?.app_name ?? 'Company';

    return (
        <AppShell title="Requisition List">
            <Head title="Requisition List" />
            <div className="space-y-6">
                <PageHeader
                    title="Daily Requisition Register"
                    description={`${companyName} — line-level register with filtered amount summary.`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            <a href={exportHref(filters)} target="_blank" rel="noopener noreferrer">
                                <Button variant="outline">
                                    <Download className="h-4 w-4" />
                                    Export Excel
                                </Button>
                            </a>
                            {canCreate && (
                                <Link href="/requisitions/create">
                                    <Button>
                                        <Plus className="h-4 w-4" />
                                        New Requisition
                                    </Button>
                                </Link>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Total Requested
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-slate-900">
                            {formatCurrency(summary.total_requested)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {pctLabel(summary.requested_pct)} · {summary.line_count} lines
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Total Paid
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-emerald-700">
                            {formatCurrency(summary.total_paid)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">{pctLabel(summary.paid_pct)} of requested</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-medium uppercase tracking-wide text-slate-500">
                            Total Pending
                        </p>
                        <p className="mt-1 text-2xl font-semibold text-amber-700">
                            {formatCurrency(summary.total_pending)}
                        </p>
                        <p className="mt-1 text-sm text-slate-500">
                            {pctLabel(summary.pending_pct)} of requested
                        </p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/requisitions"
                    filters={filters}
                    searchPlaceholder="Search req no, recipient name, description, project…"
                    sortOptions={[
                        { value: 'date', label: 'Date' },
                        { value: 'requisition_no', label: 'Requisition no' },
                        { value: 'description', label: 'Description' },
                        { value: 'amount', label: 'Amount' },
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
                        {
                            key: 'approval_status',
                            label: 'Approval',
                            emptyLabel: 'All approval states',
                            options: [
                                { value: 'pending', label: 'Pending approval' },
                                { value: 'approved', label: 'Approved / in fulfillment' },
                                { value: 'rejected', label: 'Rejected' },
                            ],
                        },
                        {
                            key: 'project_id',
                            label: 'Project',
                            emptyLabel: 'All projects',
                            options: filterOptions.projects.map((project) => ({
                                value: String(project.id),
                                label: `${project.code} — ${project.name}`,
                            })),
                        },
                        {
                            key: 'client',
                            label: 'Client',
                            emptyLabel: 'All clients',
                            options: (filterOptions.clients ?? []).map((client) => ({
                                value: client,
                                label: client,
                            })),
                        },
                        {
                            key: 'recipient_id',
                            label: 'Recipient',
                            emptyLabel: 'All recipients',
                            options: (filterOptions.recipients ?? []).map((recipient) => ({
                                value: String(recipient.id),
                                label: recipient.name,
                            })),
                        },
                        {
                            key: 'category_id',
                            label: 'Category',
                            emptyLabel: 'All categories',
                            options: filterOptions.categories.map((category) => ({
                                value: String(category.id),
                                label: category.name,
                            })),
                        },
                        {
                            key: 'requestor_id',
                            label: 'Requested by',
                            emptyLabel: 'All requestors',
                            options: filterOptions.requestors.map((user) => ({
                                value: String(user.id),
                                label: user.name,
                            })),
                        },
                    ]}
                    textFilters={[
                        { key: 'department', label: 'Department', placeholder: 'Department' },
                    ]}
                />

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="min-w-[1600px] w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-3 py-3 font-medium">Date</th>
                                    <th className="px-3 py-3 font-medium">Requested By</th>
                                    <th className="px-3 py-3 font-medium">Recipient</th>
                                    <th className="px-3 py-3 font-medium">Position</th>
                                    <th className="px-3 py-3 font-medium">Req No</th>
                                    <th className="px-3 py-3 font-medium">SN</th>
                                    <th className="px-3 py-3 font-medium">Department</th>
                                    <th className="px-3 py-3 font-medium">Description</th>
                                    <th className="px-3 py-3 font-medium">Category</th>
                                    <th className="px-3 py-3 font-medium">Project</th>
                                    <th className="px-3 py-3 font-medium">Unit</th>
                                    <th className="px-3 py-3 text-right font-medium">Qty</th>
                                    <th className="px-3 py-3 text-right font-medium">Rate</th>
                                    <th className="px-3 py-3 text-right font-medium">Amount</th>
                                    <th className="px-3 py-3 font-medium">Status</th>
                                    <th className="px-3 py-3 text-right font-medium">Paid</th>
                                    <th className="px-3 py-3 text-right font-medium">Pending</th>
                                    <th className="px-3 py-3 text-right font-medium">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {lines.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={18}
                                            className="px-6 py-12 text-center text-slate-500"
                                        >
                                            No requisition lines match the current filters.
                                        </td>
                                    </tr>
                                ) : (
                                    lines.map((row) => (
                                        <tr key={row.id} className="hover:bg-slate-50">
                                            <td className="whitespace-nowrap px-3 py-3 text-slate-600">
                                                {row.date ? formatDate(row.date) : '—'}
                                            </td>
                                            <td className="px-3 py-3 text-slate-700">
                                                {row.requested_by}
                                            </td>
                                            <td className="px-3 py-3 text-slate-700">
                                                {row.recipient_name}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">
                                                {row.recipient_position}
                                            </td>
                                            <td className="whitespace-nowrap px-3 py-3 font-mono text-slate-900">
                                                {row.requisition_no}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">{row.sn}</td>
                                            <td className="px-3 py-3 text-slate-600">
                                                {row.department}
                                            </td>
                                            <td className="max-w-[220px] px-3 py-3 text-slate-800">
                                                {row.description}
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">{row.category}</td>
                                            <td className="px-3 py-3 text-slate-600">
                                                <div className="font-mono text-xs text-slate-500">
                                                    {row.project_code}
                                                </div>
                                                <div>{row.project_name}</div>
                                            </td>
                                            <td className="px-3 py-3 text-slate-600">{row.unit}</td>
                                            <td className="px-3 py-3 text-right tabular-nums text-slate-700">
                                                {formatQuantity(row.quantity)}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-slate-700">
                                                {formatCurrency(row.rate)}
                                            </td>
                                            <td className="px-3 py-3 text-right font-medium tabular-nums text-slate-900">
                                                {formatCurrency(row.amount)}
                                            </td>
                                            <td className="px-3 py-3">
                                                <StatusBadge status={row.status} />
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-emerald-700">
                                                {formatCurrency(row.paid)}
                                            </td>
                                            <td className="px-3 py-3 text-right tabular-nums text-amber-700">
                                                {formatCurrency(row.pending)}
                                            </td>
                                            <td className="px-3 py-3 text-right">
                                                <IconLink
                                                    href={`/requisitions/${row.requisition_id}`}
                                                    icon={Eye}
                                                    label="View requisition"
                                                />
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                    <PaginationLinks paginator={rows} />
                </div>
            </div>
        </AppShell>
    );
}
