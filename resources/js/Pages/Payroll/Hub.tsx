import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { LinkButton } from '@/Components/Shared/LinkButton';
import { PageProps, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface PayrollHubProps extends PageProps {
    project: Project | null;
    projects: Pick<Project, 'id' | 'code' | 'name' | 'status'>[];
}

export default function PayrollHub() {
    const { project, projects } = usePage<PayrollHubProps>().props;

    return (
        <AppShell title="Payroll">
            <Head title="Payroll" />
            <div className="space-y-6">
                <PageHeader
                    title="Payroll"
                    description="Employees, attendance, payroll runs, and project payroll summaries."
                />

                {project ? (
                    <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <p className="text-sm text-blue-900">
                            Current project: <strong>{project.code}</strong> — {project.name}
                        </p>
                        <div className="mt-3">
                            <LinkButton href={`/payroll/${project.id}`} size="default">
                                Open project payroll
                            </LinkButton>
                        </div>
                    </div>
                ) : (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p className="text-sm text-amber-900">
                            No projects yet. Create a project to access project-scoped payroll views.
                        </p>
                        <div className="mt-3">
                            <LinkButton href="/projects/create" size="default">
                                Create a project
                            </LinkButton>
                        </div>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-3">
                    <Link
                        href="/payroll/employees"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Employees</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Manage employee records and pay structures.
                        </p>
                    </Link>
                    <Link
                        href="/payroll/attendance"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Attendance</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Record daily attendance and hours worked.
                        </p>
                    </Link>
                    <Link
                        href="/payroll/generate"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Generate Payroll</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Build and post payroll runs for a period.
                        </p>
                    </Link>
                </div>

                {projects.length > 0 && (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-900">Project payroll</h3>
                        <ul className="mt-3 divide-y divide-slate-100">
                            {projects.map((item) => (
                                <li key={item.id} className="flex items-center justify-between py-3">
                                    <div>
                                        <p className="font-medium text-slate-900">{item.name}</p>
                                        <p className="text-xs text-slate-500">{item.code}</p>
                                    </div>
                                    <LinkButton href={`/payroll/${item.id}`}>View payroll</LinkButton>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
