import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Employee, ListingFilters, PageProps, Paginated, PayrollRun, Project } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface PayrollIndexProps extends PageProps {
    project: Project;
    employees: Paginated<Employee>;
    recent_runs: Paginated<PayrollRun>;
    filters: ListingFilters;
}

type EmployeeFormData = {
    employee_no: string;
    name: string;
    role: string;
    pay_structure: 'daily' | 'monthly';
    daily_rate: string;
    monthly_salary: string;
    project_id: string;
};

const emptyForm = (projectId: number): EmployeeFormData => ({
    employee_no: '',
    name: '',
    role: '',
    pay_structure: 'monthly',
    daily_rate: '',
    monthly_salary: '',
    project_id: String(projectId),
});

export default function PayrollIndex() {
    const { project, employees, recent_runs, filters } = usePage<PayrollIndexProps>().props;
    const employeeRows = employees.data ?? [];
    const runRows = recent_runs.data ?? [];
    const [createOpen, setCreateOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);

    const createForm = useForm(emptyForm(project.id));
    const editForm = useForm(emptyForm(project.id));

    function openCreateDialog() {
        createForm.clearErrors();
        createForm.setData('project_id', String(project.id));
        setCreateOpen(true);
    }

    function closeCreateDialog() {
        if (!confirmDiscardIfDirty(createForm.isDirty)) {
            return;
        }
        setCreateOpen(false);
        createForm.reset();
        createForm.setData(emptyForm(project.id));
        createForm.clearErrors();
    }

    function openEditDialog(employee: Employee) {
        editForm.clearErrors();
        editForm.setData({
            employee_no: employee.employee_no,
            name: employee.name,
            role: employee.role,
            pay_structure: employee.pay_structure,
            daily_rate: employee.daily_rate ?? '',
            monthly_salary: employee.monthly_salary ?? '',
            project_id: String(employee.project_id || project.id),
        });
        setEditingId(employee.id);
    }

    function closeEditDialog() {
        if (!confirmDiscardIfDirty(editForm.isDirty)) {
            return;
        }
        setEditingId(null);
        editForm.reset();
        editForm.setData(emptyForm(project.id));
        editForm.clearErrors();
    }

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        createForm.transform((data) => ({
            ...data,
            project_id: Number(data.project_id),
            daily_rate: data.pay_structure === 'daily' ? data.daily_rate : null,
            monthly_salary: data.pay_structure === 'monthly' ? data.monthly_salary : null,
        }));
        createForm.post('/payroll/employees', {
            onSuccess: () => {
                createForm.reset();
                createForm.setData(emptyForm(project.id));
                setCreateOpen(false);
            },
        });
    }

    function submitEdit(e: FormEvent) {
        e.preventDefault();
        if (!editingId) {
            return;
        }
        editForm.transform((data) => ({
            ...data,
            project_id: Number(data.project_id),
            daily_rate: data.pay_structure === 'daily' ? data.daily_rate : null,
            monthly_salary: data.pay_structure === 'monthly' ? data.monthly_salary : null,
        }));
        editForm.patch(`/payroll/employees/${editingId}`, {
            onSuccess: () => {
                editForm.reset();
                editForm.setData(emptyForm(project.id));
                setEditingId(null);
            },
        });
    }

    function removeEmployee(employee: Employee) {
        if (!confirm(`Remove ${employee.name} from this project payroll?`)) {
            return;
        }
        router.delete(`/payroll/employees/${employee.id}`);
    }

    return (
        <AppShell title="Payroll">
            <Head title="Payroll" />
            <div className="space-y-6">
                <PageHeader
                    title="Payroll"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button onClick={openCreateDialog}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Employee
                            </Button>
                            <Link
                                href="/payroll/attendance"
                                className="inline-flex h-10 items-center rounded-md border border-slate-200 px-4 text-sm hover:bg-slate-50"
                            >
                                Attendance
                            </Link>
                            <Link
                                href="/payroll/generate"
                                className="inline-flex h-10 items-center rounded-md bg-blue-700 px-4 text-sm text-white hover:bg-blue-800"
                            >
                                Generate Payroll
                            </Link>
                        </div>
                    }
                />

                <ListToolbar
                    baseUrl={`/payroll/${project.id}`}
                    filters={filters}
                    searchPlaceholder="Search employee name, no…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'employee_no', label: 'Employee no' },
                        { value: 'department', label: 'Department' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title={`Employees (${employees.total})`} noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">No.</th>
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Role</th>
                                <th className="px-6 py-3 font-medium">Pay structure</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {employeeRows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No employees on this project.
                                    </td>
                                </tr>
                            ) : (
                                employeeRows.map((emp) => (
                                    <tr key={emp.id}>
                                        <td className="px-6 py-4 font-mono">{emp.employee_no}</td>
                                        <td className="px-6 py-4 font-medium">{emp.name}</td>
                                        <td className="px-6 py-4 text-slate-600">{emp.role}</td>
                                        <td className="px-6 py-4 capitalize text-slate-600">
                                            {emp.pay_structure}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex justify-end gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => openEditDialog(emp)}
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-red-200 text-red-700"
                                                    onClick={() => removeEmployee(emp)}
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

                <DataPanel
                    title={`Recent Payroll Runs (${recent_runs.total})`}
                    noPadding
                    actions={
                        <Link href="/payroll/runs" className="text-sm text-blue-700 hover:underline">
                            View all runs
                        </Link>
                    }
                >
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Run</th>
                                <th className="px-6 py-3 font-medium">Period</th>
                                <th className="px-6 py-3 font-medium">Employees</th>
                                <th className="px-6 py-3 text-right font-medium">Total Net</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {runRows.length === 0 ? (
                                <tr>
                                    <td colSpan={6} className="px-6 py-12 text-center text-slate-500">
                                        No payroll runs yet.
                                    </td>
                                </tr>
                            ) : (
                                runRows.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-6 py-4 font-mono">#{run.id}</td>
                                        <td className="px-6 py-4">
                                            {formatDate(run.period_start)} —{' '}
                                            {formatDate(run.period_end)}
                                        </td>
                                        <td className="px-6 py-4">{run.items_count ?? 0}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(run.items_sum_net_pay ?? '0')}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(run.status)} />
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <Link href={`/payroll/runs/${run.id}`}>
                                                <Button size="sm" variant="outline">
                                                    View
                                                </Button>
                                            </Link>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recent_runs} />
                </DataPanel>
            </div>

            <EmployeeDialog
                open={createOpen}
                onOpenChange={(next) => (next ? openCreateDialog() : closeCreateDialog())}
                title="Add Employee"
                description={`Create a payroll employee for ${project.name}.`}
                form={createForm}
                onSubmit={submitCreate}
                onCancel={closeCreateDialog}
                submitLabel="Add Employee"
                processingLabel="Adding…"
            />

            <EmployeeDialog
                open={editingId !== null}
                onOpenChange={(next) => {
                    if (next) {
                        return;
                    }
                    closeEditDialog();
                }}
                title="Edit Employee"
                description={`Update payroll details for ${project.name}.`}
                form={editForm}
                onSubmit={submitEdit}
                onCancel={closeEditDialog}
                submitLabel="Save Changes"
                processingLabel="Saving…"
            />
        </AppShell>
    );
}

function EmployeeDialog({
    open,
    onOpenChange,
    title,
    description,
    form,
    onSubmit,
    onCancel,
    submitLabel,
    processingLabel,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    form: ReturnType<typeof useForm<EmployeeFormData>>;
    onSubmit: (e: FormEvent) => void;
    onCancel: () => void;
    submitLabel: string;
    processingLabel: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange} title={title} description={description}>
            <form onSubmit={onSubmit} className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor={`${title}-emp-no`}>Employee no.</Label>
                    <Input
                        id={`${title}-emp-no`}
                        value={form.data.employee_no}
                        onChange={(e) => form.setData('employee_no', e.target.value)}
                        required
                    />
                    {form.errors.employee_no && (
                        <p className="text-sm text-red-600">{form.errors.employee_no}</p>
                    )}
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${title}-emp-name`}>Name</Label>
                    <Input
                        id={`${title}-emp-name`}
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        required
                    />
                    {form.errors.name && <p className="text-sm text-red-600">{form.errors.name}</p>}
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${title}-emp-role`}>Job role</Label>
                    <Input
                        id={`${title}-emp-role`}
                        value={form.data.role}
                        onChange={(e) => form.setData('role', e.target.value)}
                        required
                    />
                    {form.errors.role && <p className="text-sm text-red-600">{form.errors.role}</p>}
                </div>
                <div className="space-y-2">
                    <Label htmlFor={`${title}-emp-pay`}>Pay structure</Label>
                    <select
                        id={`${title}-emp-pay`}
                        className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
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
                        <Label htmlFor={`${title}-emp-daily`}>Daily rate</Label>
                        <Input
                            id={`${title}-emp-daily`}
                            type="number"
                            step="0.01"
                            value={form.data.daily_rate}
                            onChange={(e) => form.setData('daily_rate', e.target.value)}
                            required
                        />
                        {form.errors.daily_rate && (
                            <p className="text-sm text-red-600">{form.errors.daily_rate}</p>
                        )}
                    </div>
                ) : (
                    <div className="space-y-2">
                        <Label htmlFor={`${title}-emp-monthly`}>Monthly salary</Label>
                        <Input
                            id={`${title}-emp-monthly`}
                            type="number"
                            step="0.01"
                            value={form.data.monthly_salary}
                            onChange={(e) => form.setData('monthly_salary', e.target.value)}
                            required
                        />
                        {form.errors.monthly_salary && (
                            <p className="text-sm text-red-600">{form.errors.monthly_salary}</p>
                        )}
                    </div>
                )}
                <DialogFormActions
                    onCancel={onCancel}
                    processing={form.processing}
                    submitLabel={submitLabel}
                    processingLabel={processingLabel}
                />
            </form>
        </Dialog>
    );
}
