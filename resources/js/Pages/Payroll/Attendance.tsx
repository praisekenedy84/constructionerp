import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { PageProps, Attendance, Employee } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface AttendancePageProps extends PageProps {
    employees: Employee[];
    attendances: Attendance[];
    date: string;
}

const statusOptions = ['present', 'absent', 'half_day', 'leave'] as const;

export default function AttendancePage() {
    const { employees, attendances, date } = usePage<AttendancePageProps>().props;

    const initialEntries = employees.map((emp) => {
        const existing = attendances.find((a) => a.employee_id === emp.id);
        return {
            employee_id: emp.id,
            status: existing?.status ?? 'present',
            hours_worked: existing?.hours_worked ?? '8',
        };
    });

    const { data, setData, post, processing } = useForm({
        date,
        entries: initialEntries,
    });

    function updateEntry(index: number, field: string, value: string) {
        const entries = [...data.entries];
        entries[index] = { ...entries[index], [field]: value };
        setData('entries', entries);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/payroll/attendance');
    }

    return (
        <AppShell title="Attendance">
            <Head title="Attendance" />
            <div className="space-y-6">
                <PageHeader
                    title="Attendance Entry"
                    description="Bulk grid entry for daily attendance."
                />

                <form onSubmit={submit}>
                    <DataPanel
                        title={`Attendance — ${date}`}
                        actions={
                            <input
                                type="date"
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                                className="rounded-md border border-slate-200 px-3 py-1 text-sm"
                            />
                        }
                        noPadding
                    >
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                    <th className="px-6 py-3 font-medium">Employee</th>
                                    <th className="px-6 py-3 font-medium">Status</th>
                                    <th className="px-6 py-3 font-medium">Hours</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {employees.map((emp, index) => (
                                    <tr key={emp.id}>
                                        <td className="px-6 py-3">
                                            <p className="font-medium">{emp.name}</p>
                                            <p className="text-xs text-slate-500">{emp.employee_no}</p>
                                        </td>
                                        <td className="px-6 py-3">
                                            <select
                                                className="rounded-md border border-slate-200 px-2 py-1 text-sm"
                                                value={data.entries[index]?.status ?? 'present'}
                                                onChange={(e) =>
                                                    updateEntry(index, 'status', e.target.value)
                                                }
                                            >
                                                {statusOptions.map((s) => (
                                                    <option key={s} value={s}>
                                                        {s.replace(/_/g, ' ')}
                                                    </option>
                                                ))}
                                            </select>
                                        </td>
                                        <td className="px-6 py-3">
                                            <input
                                                type="number"
                                                step="0.5"
                                                className="w-20 rounded-md border border-slate-200 px-2 py-1 text-sm"
                                                value={data.entries[index]?.hours_worked ?? ''}
                                                onChange={(e) =>
                                                    updateEntry(index, 'hours_worked', e.target.value)
                                                }
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </DataPanel>

                    <div className="mt-4 flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving…' : 'Save Attendance'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
