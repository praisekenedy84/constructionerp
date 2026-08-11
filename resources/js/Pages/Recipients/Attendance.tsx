import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import {
    ListingFilters,
    PageProps,
    Paginated,
    Project,
    Recipient,
    RecipientAttendanceSummary,
} from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { ChevronDown, Download } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';

interface AttendancePageProps extends PageProps {
    recipients: Paginated<RecipientAttendanceSummary>;
    filters: ListingFilters & {
        project_id?: string;
        recipient_id?: string;
    };
    summary: {
        recipients: number;
        projects: number;
        associations: number;
        requisitions: number;
        staff_assignments: number;
        requisition_inclusions: number;
    };
    filterOptions: {
        projects: Pick<Project, 'id' | 'code' | 'name'>[];
        recipients: Pick<Recipient, 'id' | 'name' | 'phone' | 'status'>[];
    };
}

function exportHref(filters: Record<string, string | undefined>): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    const qs = params.toString();
    return `/recipients/attendance/export${qs ? `?${qs}` : ''}`;
}

function statusClass(status: string | undefined): string {
    return status === 'active'
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'
        : 'bg-slate-100 text-slate-600 ring-slate-500/20';
}

export default function RecipientAttendancePage() {
    const { recipients, filters, summary, filterOptions } = usePage<AttendancePageProps>().props;
    const rows = recipients.data ?? [];
    const [expandedIds, setExpandedIds] = useState<number[]>([]);

    const filterRecord = useMemo(
        () => ({
            search: filters.search,
            from: filters.from,
            to: filters.to,
            project_id: filters.project_id,
            recipient_id: filters.recipient_id,
            sort: filters.sort,
            direction: filters.direction,
        }),
        [filters],
    );

    function toggleExpanded(recipientId: number) {
        setExpandedIds((current) =>
            current.includes(recipientId)
                ? current.filter((id) => id !== recipientId)
                : [...current, recipientId],
        );
    }

    return (
        <AppShell title="Recipient Attendance">
            <Head title="Recipient Attendance" />
            <div className="space-y-6">
                <PageHeader
                    title="Recipient Attendance"
                    description="Full picture of each recipient — project count, requisition involvement, staff assignments, and per-project breakdown."
                    actions={
                        <a href={exportHref(filterRecord)} target="_blank" rel="noreferrer">
                            <Button type="button" variant="outline">
                                <Download className="mr-2 h-4 w-4" />
                                Export
                            </Button>
                        </a>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Recipients</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.recipients}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Projects Linked</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.projects}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Associations</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.associations}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Requisitions</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.requisitions}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Staff Assignments</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.staff_assignments}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Requisition Inclusions</p>
                        <p className="mt-1 text-2xl font-semibold">
                            {summary.requisition_inclusions}
                        </p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/recipients/attendance"
                    filters={filters}
                    searchPlaceholder="Search recipient, project…"
                    sortOptions={[
                        { value: 'last_seen', label: 'Last activity' },
                        { value: 'first_seen', label: 'First activity' },
                        { value: 'project_count', label: 'Projects' },
                        { value: 'requisition_count', label: 'Requisitions' },
                        { value: 'staff_project_count', label: 'Staff projects' },
                        { value: 'recipient_name', label: 'Recipient' },
                    ]}
                    selectFilters={[
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
                            key: 'recipient_id',
                            label: 'Recipient',
                            emptyLabel: 'All recipients',
                            options: filterOptions.recipients.map((recipient) => ({
                                value: String(recipient.id),
                                label: recipient.name,
                            })),
                        },
                    ]}
                />

                <DataPanel title="Recipients" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="w-10 px-4 py-3" />
                                <th className="px-6 py-3 font-medium">Recipient</th>
                                <th className="px-6 py-3 font-medium">Projects</th>
                                <th className="px-6 py-3 font-medium">Requisitions</th>
                                <th className="px-6 py-3 font-medium">Staff</th>
                                <th className="px-6 py-3 font-medium">First Activity</th>
                                <th className="px-6 py-3 font-medium">Last Activity</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500">
                                        No recipient associations match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => {
                                    const expanded = expandedIds.includes(row.recipient_id);
                                    const recipient = row.recipient;

                                    return (
                                        <Fragment key={row.id}>
                                            <tr className="bg-white">
                                                <td className="px-4 py-4">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleExpanded(row.recipient_id)}
                                                        className="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                                                        aria-expanded={expanded}
                                                        aria-label={`Show projects for ${recipient?.name ?? 'recipient'}`}
                                                    >
                                                        <ChevronDown
                                                            className={`h-4 w-4 transition-transform ${expanded ? 'rotate-180' : ''}`}
                                                        />
                                                    </button>
                                                </td>
                                                <td className="px-6 py-4">
                                                    <div className="font-medium">
                                                        {recipient?.name ?? '—'}
                                                    </div>
                                                    <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                        {recipient?.phone ? (
                                                            <span>{recipient.phone}</span>
                                                        ) : null}
                                                        {recipient?.status ? (
                                                            <span
                                                                className={`inline-flex rounded-full px-2 py-0.5 font-medium ring-1 ring-inset ${statusClass(recipient.status)}`}
                                                            >
                                                                {recipient.status}
                                                            </span>
                                                        ) : null}
                                                    </div>
                                                </td>
                                                <td className="px-6 py-4 font-medium text-slate-900">
                                                    {row.project_count}
                                                </td>
                                                <td className="px-6 py-4 text-slate-600">
                                                    {row.requisition_count}
                                                </td>
                                                <td className="px-6 py-4 text-slate-600">
                                                    {row.staff_project_count}
                                                </td>
                                                <td className="px-6 py-4 text-slate-600">
                                                    {row.first_seen ?? '—'}
                                                </td>
                                                <td className="px-6 py-4 text-slate-600">
                                                    {row.last_seen ?? '—'}
                                                </td>
                                            </tr>
                                            {expanded ? (
                                                <tr className="bg-slate-50/80">
                                                    <td colSpan={7} className="px-6 py-4">
                                                        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                                                            <table className="w-full text-sm">
                                                                <thead>
                                                                    <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                                                        <th className="px-4 py-2 font-medium">
                                                                            Project
                                                                        </th>
                                                                        <th className="px-4 py-2 font-medium">
                                                                            Link Type
                                                                        </th>
                                                                        <th className="px-4 py-2 font-medium">
                                                                            Requisitions
                                                                        </th>
                                                                        <th className="px-4 py-2 font-medium">
                                                                            First Activity
                                                                        </th>
                                                                        <th className="px-4 py-2 font-medium">
                                                                            Last Activity
                                                                        </th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody className="divide-y divide-slate-100">
                                                                    {row.projects.length === 0 ? (
                                                                        <tr>
                                                                            <td
                                                                                colSpan={5}
                                                                                className="px-4 py-6 text-center text-slate-500"
                                                                            >
                                                                                No project links found.
                                                                            </td>
                                                                        </tr>
                                                                    ) : (
                                                                        row.projects.map((projectRow) => (
                                                                            <tr key={projectRow.id}>
                                                                                <td className="px-4 py-3 text-slate-700">
                                                                                    {projectRow.project
                                                                                        ? `${projectRow.project.code} — ${projectRow.project.name}`
                                                                                        : '—'}
                                                                                </td>
                                                                                <td className="px-4 py-3">
                                                                                    <div className="flex flex-wrap gap-1.5">
                                                                                        {projectRow.is_staff ? (
                                                                                            <span className="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 ring-1 ring-inset ring-blue-600/20">
                                                                                                Staff
                                                                                            </span>
                                                                                        ) : null}
                                                                                        {projectRow.requisition_count >
                                                                                        0 ? (
                                                                                            <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">
                                                                                                Requisitions
                                                                                            </span>
                                                                                        ) : null}
                                                                                        {!projectRow.is_staff &&
                                                                                        projectRow.requisition_count ===
                                                                                            0 ? (
                                                                                            <span className="text-slate-500">
                                                                                                —
                                                                                            </span>
                                                                                        ) : null}
                                                                                    </div>
                                                                                </td>
                                                                                <td className="px-4 py-3 text-slate-600">
                                                                                    {
                                                                                        projectRow.requisition_count
                                                                                    }
                                                                                </td>
                                                                                <td className="px-4 py-3 text-slate-600">
                                                                                    {projectRow.first_seen ??
                                                                                        '—'}
                                                                                </td>
                                                                                <td className="px-4 py-3 text-slate-600">
                                                                                    {projectRow.last_seen ?? '—'}
                                                                                </td>
                                                                            </tr>
                                                                        ))
                                                                    )}
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ) : null}
                                        </Fragment>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recipients} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
