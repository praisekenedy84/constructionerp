import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import AdminNav from '@/Components/Admin/AdminNav';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Employee, ListingFilters, PageProps, Paginated, Project, User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface AdminStaffProps extends PageProps {
    employees: Paginated<Employee>;
    filters: ListingFilters;
    projects: Project[];
    linkable_users: User[];
}

export default function AdminStaff() {
    const { employees, filters, projects, linkable_users } = usePage<AdminStaffProps>().props;
    const rows = employees.data ?? [];
    const [editingId, setEditingId] = useState<number | null>(null);

    const createForm = useForm({
        employee_no: '',
        name: '',
        role: '',
        pay_structure: 'monthly' as 'daily' | 'monthly',
        daily_rate: '',
        monthly_salary: '',
        project_id: projects[0]?.id?.toString() ?? '',
        user_id: '',
    });

    const editForm = useForm({
        employee_no: '',
        name: '',
        role: '',
        pay_structure: 'monthly' as 'daily' | 'monthly',
        daily_rate: '',
        monthly_salary: '',
        project_id: '',
        user_id: '',
    });

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        createForm.transform((data) => ({
            ...data,
            project_id: Number(data.project_id),
            user_id: data.user_id ? Number(data.user_id) : null,
            daily_rate: data.pay_structure === 'daily' ? data.daily_rate : null,
            monthly_salary: data.pay_structure === 'monthly' ? data.monthly_salary : null,
        }));
        createForm.post('/admin/staff', {
            onSuccess: () => {
                createForm.reset();
                createForm.setData('project_id', projects[0]?.id?.toString() ?? '');
                createForm.setData('pay_structure', 'monthly');
            },
        });
    }

    function startEdit(employee: Employee) {
        setEditingId(employee.id);
        editForm.setData({
            employee_no: employee.employee_no,
            name: employee.name,
            role: employee.role,
            pay_structure: employee.pay_structure,
            daily_rate: employee.daily_rate ?? '',
            monthly_salary: employee.monthly_salary ?? '',
            project_id: String(employee.project_id),
            user_id: employee.user_id ? String(employee.user_id) : '',
        });
    }

    function submitEdit(e: FormEvent) {
        e.preventDefault();
        if (!editingId) return;
        editForm.transform((data) => ({
            ...data,
            project_id: Number(data.project_id),
            user_id: data.user_id ? Number(data.user_id) : null,
            daily_rate: data.pay_structure === 'daily' ? data.daily_rate : null,
            monthly_salary: data.pay_structure === 'monthly' ? data.monthly_salary : null,
        }));
        editForm.patch(`/admin/staff/${editingId}`, {
            onSuccess: () => setEditingId(null),
        });
    }

    function removeStaff(employee: Employee) {
        if (!confirm(`Remove staff record for ${employee.name}?`)) return;
        router.delete(`/admin/staff/${employee.id}`);
    }

    function PayFields({
        form,
    }: {
        form: ReturnType<typeof useForm<typeof createForm.data>>;
    }) {
        return (
            <>
                <div className="space-y-2">
                    <Label>Pay structure</Label>
                    <select
                        className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                        value={form.data.pay_structure}
                        onChange={(e) =>
                            form.setData('pay_structure', e.target.value as 'daily' | 'monthly')
                        }
                    >
                        <option value="monthly">Monthly</option>
                        <option value="daily">Daily</option>
                    </select>
                </div>
                {form.data.pay_structure === 'daily' ? (
                    <div className="space-y-2">
                        <Label>Daily rate</Label>
                        <Input
                            type="number"
                            step="0.01"
                            value={form.data.daily_rate}
                            onChange={(e) => form.setData('daily_rate', e.target.value)}
                            required
                        />
                    </div>
                ) : (
                    <div className="space-y-2">
                        <Label>Monthly salary</Label>
                        <Input
                            type="number"
                            step="0.01"
                            value={form.data.monthly_salary}
                            onChange={(e) => form.setData('monthly_salary', e.target.value)}
                            required
                        />
                    </div>
                )}
            </>
        );
    }

    return (
        <AppShell title="Staff">
            <Head title="Staff" />
            <div className="space-y-6">
                <PageHeader
                    title="Staff & employees"
                    description="Manage payroll staff records for your company. Optionally link a staff member to a system user account."
                />
                <AdminNav active="staff" />

                <DataPanel title="Add staff member">
                    <form onSubmit={submitCreate} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Employee no.</Label>
                            <Input
                                value={createForm.data.employee_no}
                                onChange={(e) => createForm.setData('employee_no', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Name</Label>
                            <Input
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Job role</Label>
                            <Input
                                value={createForm.data.role}
                                onChange={(e) => createForm.setData('role', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Project</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={createForm.data.project_id}
                                onChange={(e) => createForm.setData('project_id', e.target.value)}
                            >
                                {projects.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.code} — {p.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <PayFields form={createForm} />
                        <div className="space-y-2">
                            <Label>Link to user (optional)</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={createForm.data.user_id}
                                onChange={(e) => createForm.setData('user_id', e.target.value)}
                            >
                                <option value="">No linked account</option>
                                {linkable_users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} ({u.email})
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" disabled={createForm.processing}>
                                Add staff
                            </Button>
                        </div>
                    </form>
                </DataPanel>

                {editingId && (
                    <DataPanel title="Edit staff member">
                        <form onSubmit={submitEdit} className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Employee no.</Label>
                                <Input
                                    value={editForm.data.employee_no}
                                    onChange={(e) => editForm.setData('employee_no', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Name</Label>
                                <Input
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Job role</Label>
                                <Input
                                    value={editForm.data.role}
                                    onChange={(e) => editForm.setData('role', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Project</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={editForm.data.project_id}
                                    onChange={(e) => editForm.setData('project_id', e.target.value)}
                                >
                                    {projects.map((p) => (
                                        <option key={p.id} value={p.id}>
                                            {p.code} — {p.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <PayFields form={editForm} />
                            <div className="space-y-2">
                                <Label>Link to user (optional)</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                    value={editForm.data.user_id}
                                    onChange={(e) => editForm.setData('user_id', e.target.value)}
                                >
                                    <option value="">No linked account</option>
                                    {linkable_users.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.name} ({u.email})
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-end gap-2">
                                <Button type="submit" disabled={editForm.processing}>
                                    Save changes
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditingId(null)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </DataPanel>
                )}

                <ListToolbar
                    baseUrl="/admin/staff"
                    filters={filters}
                    searchPlaceholder="Search name, employee no…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'employee_no', label: 'Employee no' },
                        { value: 'department', label: 'Department' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Staff directory" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">No.</th>
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Role</th>
                                <th className="px-6 py-3 font-medium">Project</th>
                                <th className="px-6 py-3 font-medium">Linked user</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No staff records yet.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((employee) => (
                                    <tr key={employee.id}>
                                        <td className="px-6 py-4 font-mono">{employee.employee_no}</td>
                                        <td className="px-6 py-4 font-medium">{employee.name}</td>
                                        <td className="px-6 py-4 text-slate-600">{employee.role}</td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {employee.project?.name ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {employee.user
                                                ? `${employee.user.name} (${employee.user.email})`
                                                : '—'}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => startEdit(employee)}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-red-200 text-red-700"
                                                    onClick={() => removeStaff(employee)}
                                                >
                                                    Remove
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={employees} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
