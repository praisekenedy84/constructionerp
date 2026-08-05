import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency } from '@/lib/formatters';
import { PageProps, PayrollItem, Project } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Plus } from 'lucide-react';
import { FormEvent, useEffect, useState } from 'react';

interface GenerateProps extends PageProps {
    project: Project;
    preview: PayrollItem[] | null;
    period_start: string;
    period_end: string;
}

export default function Generate() {
    const { project, preview, period_start, period_end } = usePage<GenerateProps>().props;
    const [open, setOpen] = useState(!preview);
    const [overrides, setOverrides] = useState<Record<number, string>>({});

    const generateForm = useForm({
        project_id: project.id,
        period_start,
        period_end,
        overrides: [] as Array<{ employee_id: number; net_pay: string }>,
    });
    const postForm = useForm({ payroll_run_id: 0 });

    useEffect(() => {
        setOverrides({});
    }, [period_start, period_end, preview]);

    function openDialog() {
        generateForm.clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(generateForm.isDirty)) {
            return;
        }
        setOpen(false);
    }

    function previewPayroll(e: FormEvent) {
        e.preventDefault();
        router.get(
            '/payroll/generate',
            {
                project_id: project.id,
                period_start: generateForm.data.period_start,
                period_end: generateForm.data.period_end,
            },
            {
                onSuccess: () => setOpen(false),
            },
        );
    }

    function netFor(item: PayrollItem): string {
        return overrides[item.employee_id] ?? item.net_pay;
    }

    function isOverridden(item: PayrollItem): boolean {
        return Object.prototype.hasOwnProperty.call(overrides, item.employee_id);
    }

    function setOverride(employeeId: number, value: string) {
        setOverrides((prev) => ({ ...prev, [employeeId]: value }));
    }

    function clearOverride(employeeId: number) {
        setOverrides((prev) => {
            const next = { ...prev };
            delete next[employeeId];
            return next;
        });
    }

    function createRun() {
        const overridePayload = Object.entries(overrides).map(([employeeId, netPay]) => ({
            employee_id: Number(employeeId),
            net_pay: netPay === '' ? '0' : netPay,
        }));

        generateForm.transform((data) => ({
            ...data,
            project_id: project.id,
            period_start,
            period_end,
            overrides: overridePayload,
        }));
        generateForm.post('/payroll/generate');
    }

    const totalNet = (preview ?? []).reduce((sum, item) => {
        const net = overrides[item.employee_id] ?? item.net_pay;

        return sum + parseFloat(net || '0');
    }, 0);

    const canPost = Boolean(preview?.[0]?.payroll_run_id);

    return (
        <AppShell title="Generate Payroll">
            <Head title="Generate Payroll" />
            <div className="space-y-6">
                <PageHeader
                    title="Generate Payroll"
                    description={`Preview before posting for ${project.name}`}
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Select Pay Period
                        </Button>
                    }
                />

                {!preview && (
                    <DataPanel title="No preview yet">
                        <p className="text-sm text-slate-600">
                            Choose a pay period to preview payroll before creating a draft run or
                            posting. You can override any employee&apos;s net pay to enter an amount
                            directly without attendance.
                        </p>
                        <Button className="mt-4" onClick={openDialog}>
                            Select Pay Period
                        </Button>
                    </DataPanel>
                )}

                {preview && (
                    <>
                        <p className="text-sm text-slate-600">
                            Period:{' '}
                            <span className="font-medium text-slate-900">
                                {period_start} — {period_end}
                            </span>
                            <span className="mt-1 block text-xs text-slate-500">
                                Edit Net Pay to override attendance-based calculation. Overridden
                                amounts are used as the final total (no OT/allowances/advance
                                deductions on that line).
                            </span>
                        </p>

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
                                        <th className="px-4 py-3 font-medium">Override</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {preview.map((item) => {
                                        const overridden = isOverridden(item);

                                        return (
                                            <tr
                                                key={item.id}
                                                className={overridden ? 'bg-amber-50/60' : undefined}
                                            >
                                                <td className="px-6 py-3">
                                                    <p className="font-medium">{item.employee?.name}</p>
                                                    {overridden && (
                                                        <p className="text-xs font-medium text-amber-700">
                                                            Manual override
                                                        </p>
                                                    )}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    {overridden
                                                        ? formatCurrency(netFor(item))
                                                        : formatCurrency(item.base)}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    {overridden
                                                        ? formatCurrency('0')
                                                        : formatCurrency(item.overtime)}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    {overridden
                                                        ? formatCurrency('0')
                                                        : formatCurrency(item.allowances)}
                                                </td>
                                                <td className="px-6 py-3 text-right text-red-600">
                                                    {overridden
                                                        ? formatCurrency('0')
                                                        : formatCurrency(item.deductions_total)}
                                                </td>
                                                <td className="px-6 py-3 text-right">
                                                    <AmountInput
                                                        min="0"
                                                        className="ml-auto h-9 w-32 text-right"
                                                        value={String(netFor(item) ?? '')}
                                                        onValueChange={(v) =>
                                                            setOverride(item.employee_id, v)
                                                        }
                                                        disabled={canPost}
                                                        aria-label={`Net pay for ${item.employee?.name ?? 'employee'}`}
                                                    />
                                                </td>
                                                <td className="px-4 py-3">
                                                    {overridden && !canPost ? (
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            variant="ghost"
                                                            onClick={() =>
                                                                clearOverride(item.employee_id)
                                                            }
                                                        >
                                                            Reset
                                                        </Button>
                                                    ) : (
                                                        <span className="text-xs text-slate-400">—</span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                                <tfoot>
                                    <tr className="border-t border-slate-200 bg-slate-50">
                                        <td colSpan={5} className="px-6 py-3 text-right font-medium">
                                            Total Net Pay
                                        </td>
                                        <td className="px-6 py-3 text-right font-bold">
                                            {formatCurrency(totalNet)}
                                        </td>
                                        <td />
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
                                    Posting pays salaries from administrative cash on hand and marks
                                    the run immutable. Review the preview carefully before posting.
                                </p>
                            </div>
                        </div>

                        <div className="flex justify-end gap-3">
                            {!canPost && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={createRun}
                                    disabled={generateForm.processing}
                                >
                                    {Object.keys(overrides).length > 0
                                        ? 'Save Draft Run (with overrides)'
                                        : 'Save Draft Run'}
                                </Button>
                            )}
                            {canPost && (
                                <Link
                                    href={`/payroll/runs/${preview[0]?.payroll_run_id}`}
                                    className="inline-flex h-10 items-center rounded-md border border-slate-200 px-4 text-sm hover:bg-slate-50"
                                >
                                    View Run
                                </Link>
                            )}
                            <Button
                                onClick={() =>
                                    postForm.post(`/payroll/${preview[0]?.payroll_run_id}/post`)
                                }
                                disabled={postForm.processing || !canPost}
                                className="bg-green-700 hover:bg-green-800"
                            >
                                Post Payroll
                            </Button>
                        </div>
                    </>
                )}
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Select Pay Period"
                description="Choose the period to preview payroll for this project."
            >
                <form onSubmit={previewPayroll} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="period-start">Period Start</Label>
                        <Input
                            id="period-start"
                            type="date"
                            value={generateForm.data.period_start}
                            onChange={(e) => generateForm.setData('period_start', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="period-end">Period End</Label>
                        <Input
                            id="period-end"
                            type="date"
                            value={generateForm.data.period_end}
                            onChange={(e) => generateForm.setData('period_end', e.target.value)}
                            required
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={generateForm.processing}
                        submitLabel="Preview Payroll"
                        processingLabel="Loading…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
