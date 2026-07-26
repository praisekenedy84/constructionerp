import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { ListingFilters, PageProps, Paginated, PayrollRun, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

interface RunsProps extends PageProps {
    runs: Paginated<PayrollRun>;
    filters: ListingFilters & { project_id?: string; status?: string };
    projects: Pick<Project, 'id' | 'code' | 'name'>[];
    status_options: Array<{ value: string; label: string }>;
}

export default function PayrollRuns() {
    const { runs, filters, projects, status_options } = usePage<RunsProps>().props;
    const rows = runs.data ?? [];

    return (
        <AppShell title="Payroll Runs">
            <Head title="Payroll Runs" />
            <div className="space-y-6">
                <PageHeader
                    title="Payroll Runs"
                    description="Draft and posted payroll runs across projects."
                    actions={
                        <Link
                            href="/payroll/generate"
                            className="inline-flex h-10 items-center rounded-md bg-blue-700 px-4 text-sm text-white hover:bg-blue-800"
                        >
                            <Plus className="mr-2 h-4 w-4" />
                            Generate Payroll
                        </Link>
                    }
                />

                <ListToolbar
                    baseUrl="/payroll/runs"
                    filters={filters}
                    searchPlaceholder="Search project…"
                    sortOptions={[
                        { value: 'period_end', label: 'Period end' },
                        { value: 'period_start', label: 'Period start' },
                        { value: 'status', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: status_options,
                        },
                        ...(projects.length > 0
                            ? [
                                  {
                                      key: 'project_id',
                                      label: 'Project',
                                      emptyLabel: 'All projects',
                                      options: projects.map((p) => ({
                                          value: String(p.id),
                                          label: `${p.code} — ${p.name}`,
                                      })),
                                  },
                              ]
                            : []),
                    ]}
                />

                <DataPanel title={`Payroll Runs (${runs.total})`} noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Run</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Period</th>
                                <th className="px-6 py-3 font-medium">Employees</th>
                                <th className="px-6 py-3 text-right font-medium">Total Net</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={7} className="px-6 py-12 text-center text-slate-500">
                                        No payroll runs found. Generate and post a payroll to see it
                                        here.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-6 py-4 font-mono">#{run.id}</td>
                                        <td className="px-6 py-4">
                                            <p className="font-medium text-slate-900">
                                                {run.project?.code ?? '—'}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {run.project?.name}
                                            </p>
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(run.period_start)} —{' '}
                                            {formatDate(run.period_end)}
                                        </td>
                                        <td className="px-6 py-4">{run.items_count ?? 0}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(run.items_sum_net_pay ?? '0')}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(run.status)} />
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Link href={`/payroll/runs/${run.id}`}>
                                                    <Button size="sm" variant="outline">
                                                        View
                                                    </Button>
                                                </Link>
                                                {run.status === 'draft' && (
                                                    <Link
                                                        href={`/payroll/generate?project_id=${run.project_id}&run_id=${run.id}`}
                                                    >
                                                        <Button size="sm" variant="outline">
                                                            Review
                                                        </Button>
                                                    </Link>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={runs} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
