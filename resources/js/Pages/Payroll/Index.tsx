import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Employee, ListingFilters, PageProps, Paginated, PayrollRun, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface PayrollIndexProps extends PageProps {
    project: Project;
    employees: Paginated<Employee>;
    recent_runs: Paginated<PayrollRun>;
    filters: ListingFilters;
}

export default function PayrollIndex() {
    const { project, employees, recent_runs, filters } = usePage<PayrollIndexProps>().props;
    const employeeRows = employees.data ?? [];
    const runRows = recent_runs.data ?? [];

    return (
        <AppShell title="Payroll">
            <Head title="Payroll" />
            <div className="space-y-6">
                <PageHeader
                    title="Payroll"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <>
                            <Link
                                href="/payroll/attendance"
                                className="rounded-md border border-slate-200 px-4 py-2 text-sm hover:bg-slate-50"
                            >
                                Attendance
                            </Link>
                            <Link
                                href="/payroll/generate"
                                className="rounded-md bg-blue-700 px-4 py-2 text-sm text-white hover:bg-blue-800"
                            >
                                Generate Payroll
                            </Link>
                        </>
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
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {employeeRows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
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
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={employees} />
                </DataPanel>

                <DataPanel title={`Recent Payroll Runs (${recent_runs.total})`} noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Period</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {runRows.length === 0 ? (
                                <tr>
                                    <td colSpan={2} className="px-6 py-12 text-center text-slate-500">
                                        No payroll runs yet.
                                    </td>
                                </tr>
                            ) : (
                                runRows.map((run) => (
                                    <tr key={run.id}>
                                        <td className="px-6 py-4">
                                            {run.period_start} — {run.period_end}
                                        </td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(run.status)} />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recent_runs} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
