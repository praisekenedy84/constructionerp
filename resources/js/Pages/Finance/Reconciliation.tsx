import AppShell from '@/Components/Layout/AppShell';
import SimpleBarChart from '@/Components/Charts/SimpleBarChart';
import DataPanel from '@/Components/Shared/DataPanel';
import ExportButton from '@/Components/Shared/ExportButton';
import PageHeader from '@/Components/Shared/PageHeader';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, Project, ReconciliationSummary } from '@/types';
import { Head, usePage } from '@inertiajs/react';

interface ReconciliationProps extends PageProps {
    project: Project;
    summary: ReconciliationSummary;
}

export default function Reconciliation() {
    const { project, summary } = usePage<ReconciliationProps>().props;

    const rows = [
        { label: 'Committed', value: summary.committed, color: 'text-slate-900' },
        { label: 'Disbursed', value: summary.disbursed, color: 'text-slate-600' },
        { label: 'Outstanding', value: summary.outstanding, color: 'text-amber-700' },
        { label: 'Cash on Hand', value: summary.cash_on_hand, color: 'text-green-700' },
    ];

    return (
        <AppShell title="Reconciliation">
            <Head title="Reconciliation" />
            <div className="space-y-6">
                <PageHeader
                    title="Cash Reconciliation"
                    description={`${project.code} — Outstanding = Committed − Disbursed`}
                    actions={
                        <ExportButton
                            slug="cash-reconciliation"
                            filters={{ project_id: project.id }}
                        />
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    {rows.map((row) => (
                        <DataPanel key={row.label} title={row.label}>
                            <p className={`text-2xl font-bold ${row.color}`}>
                                {formatCurrency(row.value)}
                            </p>
                        </DataPanel>
                    ))}
                </div>

                <DataPanel title="Reconciliation Breakdown">
                    <SimpleBarChart
                        data={rows.map((row) => ({
                            name: row.label,
                            amount: parseFloat(row.value) || 0,
                        }))}
                        xKey="name"
                        series={[{ key: 'amount', name: 'Amount (TZS)', color: '#1d4ed8' }]}
                    />
                </DataPanel>

                <DataPanel title="Reconciliation Formula">
                    <div className="space-y-2 font-mono text-sm text-slate-700">
                        <p>Outstanding = Committed − Disbursed</p>
                        <p>
                            {formatCurrency(summary.outstanding)} ={' '}
                            {formatCurrency(summary.committed)} −{' '}
                            {formatCurrency(summary.disbursed)}
                        </p>
                        <p className="mt-4 text-slate-500">
                            Cash on Hand = Received − Utilized (independent query)
                        </p>
                    </div>
                </DataPanel>
            </div>
        </AppShell>
    );
}
