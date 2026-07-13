import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ExportButton from '@/Components/Shared/ExportButton';
import PageHeader from '@/Components/Shared/PageHeader';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps, Project, ReportDefinition } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface ReportsShowProps extends PageProps {
    report: ReportDefinition;
    data: Record<string, unknown>[];
    columns: { key: string; label: string }[];
    filters: Record<string, string>;
    projects: Project[];
}

export default function ReportsShow() {
    const { report, data, columns, filters, projects } = usePage<ReportsShowProps>().props;

    function applyFilter(key: string, value: string) {
        router.get(
            `/reports/preview/${report.slug}`,
            { ...filters, [key]: value || undefined },
            { preserveState: true },
        );
    }

    return (
        <AppShell title={report.name}>
            <Head title={report.name} />
            <div className="space-y-6">
                <PageHeader
                    title={report.name}
                    description={report.description}
                    actions={
                        <>
                            <ExportButton slug={report.slug} format="csv" filters={filters} />
                            <ExportButton slug={report.slug} format="xlsx" filters={filters} />
                            <ExportButton slug={report.slug} format="pdf" filters={filters} />
                        </>
                    }
                />

                <DataPanel title="Filters">
                    <div className="flex flex-wrap gap-4">
                        <div className="space-y-2">
                            <Label>Project</Label>
                            <select
                                className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                                value={filters.project_id ?? ''}
                                onChange={(e) => applyFilter('project_id', e.target.value)}
                            >
                                <option value="">All projects</option>
                                {projects.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} — {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>From</Label>
                            <Input
                                type="date"
                                defaultValue={filters.from}
                                onBlur={(e) => applyFilter('from', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>To</Label>
                            <Input
                                type="date"
                                defaultValue={filters.to}
                                onBlur={(e) => applyFilter('to', e.target.value)}
                            />
                        </div>
                    </div>
                </DataPanel>

                <DataPanel title="Report Data" noPadding>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    {columns.map((col) => (
                                        <th key={col.key} className="px-6 py-3 font-medium">
                                            {col.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {data.length === 0 ? (
                                    <tr>
                                        <td
                                            colSpan={columns.length}
                                            className="px-6 py-12 text-center text-slate-500"
                                        >
                                            No data for selected filters.
                                        </td>
                                    </tr>
                                ) : (
                                    data.map((row, i) => (
                                        <tr key={i}>
                                            {columns.map((col) => (
                                                <td key={col.key} className="px-6 py-3 text-slate-700">
                                                    {String(row[col.key] ?? '—')}
                                                </td>
                                            ))}
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </DataPanel>
            </div>
        </AppShell>
    );
}
