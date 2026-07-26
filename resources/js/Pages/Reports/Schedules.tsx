import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatDate } from '@/lib/formatters';
import { PageProps, ReportDefinition, ReportSchedule } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface SchedulesProps extends PageProps {
    schedules: ReportSchedule[];
    reports: ReportDefinition[];
}

export default function Schedules() {
    const { schedules, reports } = usePage<SchedulesProps>().props;
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        report_slug: '',
        frequency: 'weekly',
        recipients: '',
    });

    function openDialog() {
        clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset();
        clearErrors();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/reports/schedules', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Report Schedules">
            <Head title="Report Schedules" />
            <div className="space-y-6">
                <PageHeader
                    title="Report Schedules"
                    description="Automated report delivery via email."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Create Schedule
                        </Button>
                    }
                />

                <DataPanel title="Active Schedules" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Report</th>
                                <th className="px-6 py-3 font-medium">Frequency</th>
                                <th className="px-6 py-3 font-medium">Recipients</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 font-medium">Last Run</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {schedules.map((sched) => (
                                <tr key={sched.id}>
                                    <td className="px-6 py-4">{sched.report_slug}</td>
                                    <td className="px-6 py-4 capitalize">{sched.frequency}</td>
                                    <td className="px-6 py-4 text-slate-600">
                                        {sched.recipients.join(', ')}
                                    </td>
                                    <td className="px-6 py-4">
                                        <StatusBadge
                                            status={sched.is_active ? 'active' : 'closed'}
                                        />
                                    </td>
                                    <td className="px-6 py-4 text-slate-600">
                                        {sched.last_run_at ? formatDate(sched.last_run_at) : '—'}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Create Schedule"
                description="Schedule automated report delivery via email."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="schedule-report">Report</Label>
                        <select
                            id="schedule-report"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.report_slug}
                            onChange={(e) => setData('report_slug', e.target.value)}
                            required
                        >
                            <option value="">Select report</option>
                            {reports.map((r) => (
                                <option key={r.slug} value={r.slug}>
                                    {r.name}
                                </option>
                            ))}
                        </select>
                        {errors.report_slug && (
                            <p className="text-sm text-red-600">{errors.report_slug}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="schedule-frequency">Frequency</Label>
                        <select
                            id="schedule-frequency"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
                            value={data.frequency}
                            onChange={(e) => setData('frequency', e.target.value)}
                        >
                            <option value="daily">Daily</option>
                            <option value="weekly">Weekly</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="schedule-recipients">Recipients (comma-separated emails)</Label>
                        <Input
                            id="schedule-recipients"
                            value={data.recipients}
                            onChange={(e) => setData('recipients', e.target.value)}
                            placeholder="finance@company.com, md@company.com"
                            required
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Create Schedule"
                        processingLabel="Creating…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
