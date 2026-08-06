import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { hasPermission } from '@/lib/permissions';
import {
    ListingFilters,
    PageProps,
    Paginated,
    Project,
    Recipient,
    RecipientAttendance,
} from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Download, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useMemo, useState } from 'react';

interface AttendancePageProps extends PageProps {
    attendances: Paginated<RecipientAttendance>;
    filters: ListingFilters & {
        project_id?: string;
        recipient_id?: string;
        status?: string;
    };
    summary: {
        days_present: number;
        days_absent: number;
        total_records: number;
    };
    filterOptions: {
        projects: Pick<Project, 'id' | 'code' | 'name'>[];
        recipients: Pick<Recipient, 'id' | 'name' | 'phone' | 'status'>[];
    };
}

function exportHref(filters: Record<string, string | undefined>): string {
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    const qs = params.toString();
    return `/recipients/attendance/export${qs ? `?${qs}` : ''}`;
}

export default function RecipientAttendancePage() {
    const { attendances, filters, summary, filterOptions, auth } =
        usePage<AttendancePageProps>().props;
    const rows = attendances.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');
    const [open, setOpen] = useState(false);

    const filterRecord = useMemo(
        () => ({
            search: filters.search,
            from: filters.from,
            to: filters.to,
            project_id: filters.project_id,
            recipient_id: filters.recipient_id,
            status: filters.status,
            sort: filters.sort,
            direction: filters.direction,
        }),
        [filters],
    );

    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        recipient_id: '',
        project_id: '',
        date: new Date().toISOString().slice(0, 10),
        check_in: '',
        check_out: '',
        status: 'present' as 'present' | 'absent',
        notes: '',
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
        post('/recipients/attendance', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    function remove(id: number) {
        if (!confirm('Delete this attendance record?')) {
            return;
        }
        router.delete(`/recipients/attendance/${id}`);
    }

    return (
        <AppShell title="Recipient Attendance">
            <Head title="Recipient Attendance" />
            <div className="space-y-6">
                <PageHeader
                    title="Recipient Attendance"
                    description="Track recipient attendance per project with check-in and check-out."
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <a href={exportHref(filterRecord)} target="_blank" rel="noreferrer">
                                <Button type="button" variant="outline">
                                    <Download className="mr-2 h-4 w-4" />
                                    Export
                                </Button>
                            </a>
                            {canCreate && (
                                <Button onClick={openDialog}>
                                    <Plus className="mr-2 h-4 w-4" />
                                    Record Attendance
                                </Button>
                            )}
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Days Present</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.days_present}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Days Absent</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.days_absent}</p>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs text-slate-500">Total Records</p>
                        <p className="mt-1 text-2xl font-semibold">{summary.total_records}</p>
                    </div>
                </div>

                <ListToolbar
                    baseUrl="/recipients/attendance"
                    filters={filters}
                    searchPlaceholder="Search recipient, project…"
                    sortOptions={[
                        { value: 'date', label: 'Date' },
                        { value: 'status', label: 'Status' },
                        { value: 'created_at', label: 'Created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'project_id',
                            label: 'Project',
                            emptyLabel: 'All projects',
                            options: filterOptions.projects.map((project) => ({
                                value: String(project.id),
                                label: `${project.code} — ${project.name}`,
                            })),
                        },
                        {
                            key: 'recipient_id',
                            label: 'Recipient',
                            emptyLabel: 'All recipients',
                            options: filterOptions.recipients.map((recipient) => ({
                                value: String(recipient.id),
                                label: recipient.name,
                            })),
                        },
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: [
                                { value: 'present', label: 'Present' },
                                { value: 'absent', label: 'Absent' },
                            ],
                        },
                    ]}
                />

                <DataPanel title="Attendance Records" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Recipient</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Check In</th>
                                <th className="px-6 py-3 font-medium">Check Out</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                {canUpdate && (
                                    <th className="px-6 py-3 text-right font-medium">Actions</th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={canUpdate ? 7 : 6}
                                        className="px-6 py-12 text-center text-slate-500"
                                    >
                                        No attendance records match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((row) => (
                                    <tr key={row.id}>
                                        <td className="px-6 py-4 font-medium">
                                            {row.recipient?.name ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {row.project
                                                ? `${row.project.code} — ${row.project.name}`
                                                : '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">{row.date}</td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {row.check_in ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {row.check_out ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 capitalize text-slate-600">
                                            {row.status}
                                        </td>
                                        {canUpdate && (
                                            <td className="px-6 py-4 text-right">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => remove(row.id)}
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={attendances} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Record Recipient Attendance"
                description="Save attendance for a recipient on a project."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label>Recipient</Label>
                        <select
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                            value={data.recipient_id}
                            onChange={(e) => setData('recipient_id', e.target.value)}
                            required
                        >
                            <option value="">Select recipient</option>
                            {filterOptions.recipients.map((recipient) => (
                                <option key={recipient.id} value={recipient.id}>
                                    {recipient.name}
                                    {recipient.status !== 'active' ? ' (inactive)' : ''}
                                </option>
                            ))}
                        </select>
                        {errors.recipient_id && (
                            <p className="text-sm text-red-600">{errors.recipient_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label>Project</Label>
                        <select
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                            value={data.project_id}
                            onChange={(e) => setData('project_id', e.target.value)}
                            required
                        >
                            <option value="">Select project</option>
                            {filterOptions.projects.map((project) => (
                                <option key={project.id} value={project.id}>
                                    {project.code} — {project.name}
                                </option>
                            ))}
                        </select>
                        {errors.project_id && (
                            <p className="text-sm text-red-600">{errors.project_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label>Date</Label>
                        <Input
                            type="date"
                            value={data.date}
                            onChange={(e) => setData('date', e.target.value)}
                            required
                        />
                        {errors.date && <p className="text-sm text-red-600">{errors.date}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label>Status</Label>
                        <select
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                            value={data.status}
                            onChange={(e) =>
                                setData('status', e.target.value as 'present' | 'absent')
                            }
                        >
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                        </select>
                    </div>
                    {data.status === 'present' && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Check In</Label>
                                <Input
                                    type="time"
                                    value={data.check_in}
                                    onChange={(e) => setData('check_in', e.target.value)}
                                />
                                {errors.check_in && (
                                    <p className="text-sm text-red-600">{errors.check_in}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Check Out</Label>
                                <Input
                                    type="time"
                                    value={data.check_out}
                                    onChange={(e) => setData('check_out', e.target.value)}
                                />
                                {errors.check_out && (
                                    <p className="text-sm text-red-600">{errors.check_out}</p>
                                )}
                            </div>
                        </div>
                    )}
                    <div className="space-y-2">
                        <Label>Notes</Label>
                        <Input
                            value={data.notes}
                            onChange={(e) => setData('notes', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Save Attendance"
                        processingLabel="Saving…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
