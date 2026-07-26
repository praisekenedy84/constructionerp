import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { PageProps, PayrollRun } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';

interface ShowProps extends PageProps {
    run: PayrollRun;
    total_net_pay: string;
}

export default function PayrollShow() {
    const { run, total_net_pay } = usePage<ShowProps>().props;
    const items = run.items ?? [];
    const isDraft = run.status === 'draft';

    function postRun() {
        if (!confirm('Post this payroll run? This records salaries as overhead expense and cannot be undone.')) {
            return;
        }
        router.post(`/payroll/${run.id}/post`);
    }

    return (
        <AppShell title={`Payroll Run #${run.id}`}>
            <Head title={`Payroll Run #${run.id}`} />
            <div className="space-y-6">
                <PageHeader
                    title={`Payroll Run #${run.id}`}
                    description={`${run.project?.code ?? ''} — ${run.project?.name ?? 'Project'} · ${formatDate(run.period_start)} to ${formatDate(run.period_end)}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Link href="/payroll/runs">
                                <Button variant="outline">Back to runs</Button>
                            </Link>
                            {isDraft && (
                                <>
                                    <Link
                                        href={`/payroll/generate?project_id=${run.project_id}&run_id=${run.id}`}
                                    >
                                        <Button variant="outline">Open in Generate</Button>
                                    </Link>
                                    <Button
                                        className="bg-green-700 hover:bg-green-800"
                                        onClick={postRun}
                                    >
                                        Post Payroll
                                    </Button>
                                </>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Status</p>
                        <div className="mt-2">
                            <StatusBadge status={String(run.status)} />
                        </div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Employees</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">
                            {run.items_count ?? items.length}
                        </p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm sm:col-span-2">
                        <p className="text-xs text-slate-500">Total Net Pay</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900">
                            {formatCurrency(total_net_pay)}
                        </p>
                    </div>
                </div>

                <DataPanel title="Payroll Items" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Employee</th>
                                <th className="px-6 py-3 text-right font-medium">Base</th>
                                <th className="px-6 py-3 text-right font-medium">OT</th>
                                <th className="px-6 py-3 text-right font-medium">Allowances</th>
                                <th className="px-6 py-3 text-right font-medium">Deductions</th>
                                <th className="px-6 py-3 text-right font-medium">Net Pay</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {items.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No payroll items on this run.
                                    </td>
                                </tr>
                            ) : (
                                items.map((item) => (
                                    <tr key={item.id}>
                                        <td className="px-6 py-4">
                                            <p className="font-medium">{item.employee?.name}</p>
                                            <p className="text-xs text-slate-500">
                                                {item.employee?.employee_no}
                                            </p>
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {formatCurrency(item.base)}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {formatCurrency(item.overtime)}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            {formatCurrency(item.allowances)}
                                        </td>
                                        <td className="px-6 py-4 text-right text-red-600">
                                            {formatCurrency(item.deductions_total)}
                                        </td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(item.net_pay)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                        <tfoot>
                            <tr className="border-t border-slate-200 bg-slate-50">
                                <td colSpan={5} className="px-6 py-3 text-right font-medium">
                                    Total Net Pay
                                </td>
                                <td className="px-6 py-3 text-right font-bold">
                                    {formatCurrency(total_net_pay)}
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </DataPanel>
            </div>
        </AppShell>
    );
}
