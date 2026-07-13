import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatDate } from '@/lib/formatters';
import { PageProps, ReportDefinition, ReportSchedule } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface SchedulesProps extends PageProps {
    schedules: ReportSchedule[];
    reports: ReportDefinition[];
}

export default function Schedules() {
    const { schedules, reports } = usePage<SchedulesProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        report_slug: '',
        frequency: 'weekly',
        recipients: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/reports/schedules', {
            onSuccess: () => reset(),
        });
    }

    return (
        <AppShell title="Report Schedules">
            <Head title="Report Schedules" />
            <div className="space-y-6">
                <PageHeader
                    title="Report Schedules"
                    description="Automated report delivery via email."
                />

                <DataPanel title="Create Schedule">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Report</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
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
                            <Label>Frequency</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={data.frequency}
                                onChange={(e) => setData('frequency', e.target.value)}
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label>Recipients (comma-separated emails)</Label>
                            <Input
                                value={data.recipients}
                                onChange={(e) => setData('recipients', e.target.value)}
                                placeholder="finance@company.com, md@company.com"
                                required
                            />
                        </div>
                        <div>
                            <Button type="submit" disabled={processing}>
                                Create Schedule
                            </Button>
                        </div>
                    </form>
                </DataPanel>

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
        </AppShell>
    );
}
