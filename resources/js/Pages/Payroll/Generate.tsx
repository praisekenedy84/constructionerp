import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, PayrollItem, Project } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { FormEvent } from 'react';

interface GenerateProps extends PageProps {
    project: Project;
    preview: PayrollItem[] | null;
    period_start: string;
    period_end: string;
}

export default function Generate() {
    const { project, preview, period_start, period_end } = usePage<GenerateProps>().props;
    const generateForm = useForm({
        project_id: project.id,
        period_start,
        period_end,
    });
    const postForm = useForm({ payroll_run_id: 0 });

    function generate(e: FormEvent) {
        e.preventDefault();
        router.get('/payroll/generate', {
            project_id: project.id,
            period_start: generateForm.data.period_start,
            period_end: generateForm.data.period_end,
        });
    }

    function createRun() {
        generateForm.post('/payroll/generate');
    }

    const totalNet = (preview ?? []).reduce(
        (sum, item) => sum + parseFloat(item.net_pay),
        0,
    );

    return (
        <AppShell title="Generate Payroll">
            <Head title="Generate Payroll" />
            <div className="space-y-6">
                <PageHeader
                    title="Generate Payroll"
                    description={`Preview before posting for ${project.name}`}
                />

                <DataPanel title="Pay Period">
                    <form onSubmit={generate} className="flex flex-wrap items-end gap-4">
                        <div className="space-y-2">
                            <Label>Period Start</Label>
                            <Input
                                type="date"
                                value={generateForm.data.period_start}
                                onChange={(e) =>
                                    generateForm.setData('period_start', e.target.value)
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Period End</Label>
                            <Input
                                type="date"
                                value={generateForm.data.period_end}
                                onChange={(e) =>
                                    generateForm.setData('period_end', e.target.value)
                                }
                            />
                        </div>
                        <Button type="submit" disabled={generateForm.processing}>
                            Preview Payroll
                        </Button>
                    </form>
                </DataPanel>

                {preview && (
                    <>
                        <DataPanel title="Payroll Preview" noPadding>
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
                                    {preview.map((item) => (
                                        <tr key={item.id}>
                                            <td className="px-6 py-3">{item.employee?.name}</td>
                                            <td className="px-6 py-3 text-right">
                                                {formatCurrency(item.base)}
                                            </td>
                                            <td className="px-6 py-3 text-right">
                                                {formatCurrency(item.overtime)}
                                            </td>
                                            <td className="px-6 py-3 text-right">
                                                {formatCurrency(item.allowances)}
                                            </td>
                                            <td className="px-6 py-3 text-right text-red-600">
                                                {formatCurrency(item.deductions_total)}
                                            </td>
                                            <td className="px-6 py-3 text-right font-medium">
                                                {formatCurrency(item.net_pay)}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-200 bg-slate-50">
                                        <td colSpan={5} className="px-6 py-3 text-right font-medium">
                                            Total Net Pay
                                        </td>
                                        <td className="px-6 py-3 text-right font-bold">
                                            {formatCurrency(totalNet)}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </DataPanel>

                        <div className="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                            <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-600" />
                            <div>
                                <p className="text-sm font-medium text-amber-800">
                                    Posting is irreversible
                                </p>
                                <p className="mt-1 text-sm text-amber-700">
                                    Posting creates PAYROLL budget transactions and marks the run
                                    immutable. Review the preview carefully before posting.
                                </p>
                            </div>
                        </div>

                        <div className="flex justify-end gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={createRun}
                                disabled={generateForm.processing}
                            >
                                Save Draft Run
                            </Button>
                            <Button
                                onClick={() => postForm.post(`/payroll/${preview[0]?.payroll_run_id}/post`)}
                                disabled={postForm.processing || !preview[0]?.payroll_run_id}
                                className="bg-green-700 hover:bg-green-800"
                            >
                                Post Payroll
                            </Button>
                        </div>
                    </>
                )}
            </div>
        </AppShell>
    );
}
