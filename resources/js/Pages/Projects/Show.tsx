import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { PageProps, Project } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Pencil, Trash2 } from 'lucide-react';

type Tab = 'overview' | 'boq' | 'budget' | 'requisitions' | 'finance' | 'reports';

interface ProjectsShowProps extends PageProps {
    project: Project;
    tab?: Tab;
}

const tabs: { key: Tab; label: string; href: (id: number) => string }[] = [
    { key: 'overview', label: 'Overview', href: (id) => `/projects/${id}` },
    { key: 'boq', label: 'BOQ', href: (id) => `/projects/${id}/boq` },
    { key: 'budget', label: 'Budget', href: (id) => `/projects/${id}/budget` },
    { key: 'requisitions', label: 'Requisitions', href: (id) => `/requisitions?project_id=${id}` },
    { key: 'finance', label: 'Finance', href: (id) => `/finance/${id}` },
    { key: 'reports', label: 'Reports', href: (id) => `/reports?project_id=${id}` },
];

export default function ProjectsShow() {
    const { project, tab = 'overview', auth } = usePage<ProjectsShowProps>().props;
    const canUpdate = hasPermission(auth.user, 'projects', 'update');
    const canDelete = hasPermission(auth.user, 'projects', 'delete-soft');

    function archiveProject() {
        if (!confirm(`Archive project "${project.code} — ${project.name}"?`)) {
            return;
        }

        router.delete(`/projects/${project.id}`);
    }

    return (
        <AppShell title={project.name}>
            <Head title={project.name} />
            <div className="space-y-6">
                <PageHeader
                    title={project.name}
                    description={`${project.code} · ${project.client} · ${project.location}`}
                    actions={
                        <div className="flex flex-wrap items-center gap-2">
                            {canUpdate && (
                                <Link href={`/projects/${project.id}/edit`}>
                                    <Button variant="outline" size="sm">
                                        <Pencil className="h-4 w-4" />
                                        Edit
                                    </Button>
                                </Link>
                            )}
                            {canDelete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    className="border-red-200 text-red-700 hover:bg-red-50"
                                    onClick={archiveProject}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    Archive
                                </Button>
                            )}
                            <Link href={`/projects/${project.id}/valuations`}>
                                <Button variant="outline" size="sm">
                                    Valuations
                                </Button>
                            </Link>
                        </div>
                    }
                />

                <div className="grid gap-4 sm:grid-cols-4">
                    <DataPanel title="Net Budget">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.net_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Remaining">
                        <p className="text-2xl font-bold text-green-700">
                            {formatCurrency(project.remaining_budget ?? project.net_budget)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Contract Amount">
                        <p className="text-2xl font-bold text-slate-900">
                            {formatCurrency(project.contract_amount)}
                        </p>
                    </DataPanel>
                    <DataPanel title="Progress">
                        <p className="text-2xl font-bold text-blue-700">
                            {formatPercent(project.physical_progress_pct)}
                        </p>
                    </DataPanel>
                </div>

                <nav className="flex gap-1 border-b border-slate-200">
                    {tabs.map((t) => (
                        <Link
                            key={t.key}
                            href={t.href(project.id)}
                            className={cn(
                                'px-4 py-2 text-sm font-medium transition-colors',
                                tab === t.key
                                    ? 'border-b-2 border-blue-700 text-blue-700'
                                    : 'text-slate-500 hover:text-slate-900',
                            )}
                        >
                            {t.label}
                        </Link>
                    ))}
                </nav>

                <DataPanel title="Project Overview">
                    <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt className="text-xs text-slate-500">Status</dt>
                            <dd className="mt-1">
                            <StatusBadge status={String(project.status)} />
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">WHT %</dt>
                            <dd className="mt-1 text-sm text-slate-900">
                                {formatPercent(project.wht_percentage)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">Start Date</dt>
                            <dd className="mt-1 text-sm text-slate-900">
                                {formatDate(project.start_date)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-slate-500">End Date</dt>
                            <dd className="mt-1 text-sm text-slate-900">
                                {formatDate(project.end_date)}
                            </dd>
                        </div>
                    </dl>
                </DataPanel>
            </div>
        </AppShell>
    );
}
