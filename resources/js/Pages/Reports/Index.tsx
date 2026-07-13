import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { PageProps, ReportDefinition } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface ReportsIndexProps extends PageProps {
    reports: ReportDefinition[];
}

export default function ReportsIndex() {
    const { reports } = usePage<ReportsIndexProps>().props;

    const grouped = reports.reduce<Record<string, ReportDefinition[]>>((acc, report) => {
        if (!acc[report.category]) acc[report.category] = [];
        acc[report.category].push(report);
        return acc;
    }, {});

    return (
        <AppShell title="Reports">
            <Head title="Reports" />
            <div className="space-y-6">
                <PageHeader
                    title="Reports"
                    description="Report catalog with export and scheduling."
                    actions={
                        <Link
                            href="/reports/schedules"
                            className="rounded-md border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                        >
                            Manage Schedules
                        </Link>
                    }
                />

                {Object.entries(grouped).map(([category, items]) => (
                    <div key={category}>
                        <h3 className="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">
                            {category}
                        </h3>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {items.map((report) => (
                                <Link
                                    key={report.slug}
                                    href={`/reports/preview/${report.slug}`}
                                    className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm hover:border-blue-300"
                                >
                                    <p className="font-medium text-slate-900">{report.name}</p>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {report.description}
                                    </p>
                                </Link>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        </AppShell>
    );
}
