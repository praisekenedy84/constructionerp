import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatDate } from '@/lib/formatters';
import {
    Equipment,
    EquipmentAssignment,
    ListingFilters,
    PageProps,
    Paginated,
    Project,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface AssignmentsProps extends PageProps {
    assignments: Paginated<EquipmentAssignment>;
    filters: ListingFilters;
    equipment: Equipment[];
    projects: Project[];
}

export default function Assignments() {
    const { assignments, filters, equipment, projects } = usePage<AssignmentsProps>().props;
    const rows = assignments.data ?? [];
    const { data, setData, post, processing, reset } = useForm({
        equipment_id: '',
        project_id: '',
        hours_budgeted: '',
        start_date: new Date().toISOString().split('T')[0],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/equipment/assignments', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Equipment Assignments">
            <Head title="Equipment Assignments" />
            <div className="space-y-6">
                <PageHeader title="Equipment Assignments" description="Assign equipment to projects." />

                <DataPanel title="New Assignment">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Equipment</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={data.equipment_id}
                                onChange={(e) => setData('equipment_id', e.target.value)}
                                required
                            >
                                <option value="">Select equipment</option>
                                {equipment.map((eq) => (
                                    <option key={eq.id} value={eq.id}>
                                        {eq.name} ({eq.type})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>Project</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={data.project_id}
                                onChange={(e) => setData('project_id', e.target.value)}
                                required
                            >
                                <option value="">Select project</option>
                                {projects.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} — {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>Hours Budgeted</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.hours_budgeted}
                                onChange={(e) => setData('hours_budgeted', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Start Date</Label>
                            <Input
                                type="date"
                                value={data.start_date}
                                onChange={(e) => setData('start_date', e.target.value)}
                            />
                        </div>
                        <div>
                            <Button type="submit" disabled={processing}>
                                Assign
                            </Button>
                        </div>
                    </form>
                </DataPanel>

                <ListToolbar
                    baseUrl="/equipment/assignments"
                    filters={filters}
                    searchPlaceholder="Search equipment, project…"
                    sortOptions={[
                        { value: 'start_date', label: 'Start date' },
                        { value: 'end_date', label: 'End date' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Active Assignments" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Equipment</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Hours Used / Budgeted</th>
                                <th className="px-6 py-3 font-medium">Start</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
                                        No assignments found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((a) => (
                                    <tr key={a.id}>
                                        <td className="px-6 py-4">{a.equipment?.name}</td>
                                        <td className="px-6 py-4">{a.project?.name}</td>
                                        <td className="px-6 py-4">
                                            {a.hours_used} / {a.hours_budgeted ?? '—'}
                                        </td>
                                        <td className="px-6 py-4">{formatDate(a.start_date)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={assignments} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
