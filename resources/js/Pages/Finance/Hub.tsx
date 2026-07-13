import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { LinkButton } from '@/Components/Shared/LinkButton';
import { PageProps, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

interface FinanceHubProps extends PageProps {
    project: Project | null;
    projects: Pick<Project, 'id' | 'code' | 'name' | 'status'>[];
}

export default function FinanceHub() {
    const { project, projects } = usePage<FinanceHubProps>().props;

    return (
        <AppShell title="Finance">
            <Head title="Finance" />
            <div className="space-y-6">
                <PageHeader
                    title="Finance"
                    description="Cash management, expenses, fund approvals, and project financial dashboards."
                />

                {project ? (
                    <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <p className="text-sm text-blue-900">
                            Current project: <strong>{project.code}</strong> — {project.name}
                        </p>
                        <div className="mt-3">
                            <LinkButton href={`/finance/${project.id}`} size="default">
                                Open project finance dashboard
                            </LinkButton>
                        </div>
                    </div>
                ) : (
                    <div className="rounded-xl border border-amber-200 bg-amber-50 p-4">
                        <p className="text-sm text-amber-900">
                            No projects yet. Create a project to access project-scoped finance views.
                        </p>
                        <div className="mt-3">
                            <LinkButton href="/projects/create" size="default">
                                Create a project
                            </LinkButton>
                        </div>
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        href="/finance/approvals"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Fund Approvals</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Review and approve cash allocation requests.
                        </p>
                    </Link>
                    <Link
                        href="/finance/expenses"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Expenses</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Record and review direct project expenses.
                        </p>
                    </Link>
                    <Link
                        href="/finance/overhead"
                        className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm hover:border-blue-300"
                    >
                        <p className="text-lg font-semibold text-slate-900">Overhead</p>
                        <p className="mt-2 text-sm text-slate-500">
                            Track indirect and overhead costs.
                        </p>
                    </Link>
                </div>

                {projects.length > 0 && (
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="text-sm font-semibold text-slate-900">Project dashboards</h3>
                        <ul className="mt-3 divide-y divide-slate-100">
                            {projects.map((item) => (
                                <li key={item.id} className="flex items-center justify-between py-3">
                                    <div>
                                        <p className="font-medium text-slate-900">{item.name}</p>
                                        <p className="text-xs text-slate-500">{item.code}</p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
                                        <LinkButton href={`/finance/${item.id}`}>Dashboard</LinkButton>
                                        <LinkButton href={`/finance/${item.id}/cash-flow`}>Cash flow</LinkButton>
                                        <LinkButton href={`/finance/reconciliation/${item.id}`}>
                                            Reconciliation
                                        </LinkButton>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppShell>
    );
}
