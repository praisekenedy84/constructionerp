import AppShell from '@/Components/Layout/AppShell';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { IconLink, LinkButton } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatDate, formatPercent } from '@/lib/formatters';
import { hasPermission } from '@/lib/permissions';
import { ListingFilters, PageProps, Paginated, Project } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Eye, Pencil, Plus, Trash2 } from 'lucide-react';

interface ProjectsIndexProps extends PageProps {
    projects: Paginated<Project>;
    filters: ListingFilters;
}

export default function ProjectsIndex() {
    const { projects, filters, auth } = usePage<ProjectsIndexProps>().props;
    const rows = projects.data ?? [];
    const canCreate = hasPermission(auth.user, 'projects', 'create');
    const canUpdate = hasPermission(auth.user, 'projects', 'update');
    const canDelete = hasPermission(auth.user, 'projects', 'delete-soft');

    function archiveProject(project: Project) {
        if (!confirm(`Archive project "${project.code} — ${project.name}"?`)) {
            return;
        }

        router.delete(`/projects/${project.id}`);
    }

    return (
        <AppShell title="Projects">
            <Head title="Projects" />
            <div className="space-y-6">
                <PageHeader
                    title="Projects"
                    description="Manage construction projects, budgets, and BOQ."
                    actions={
                        canCreate ? (
                            <Link href="/projects/create">
                                <Button>
                                    <Plus className="h-4 w-4" />
                                    New Project
                                </Button>
                            </Link>
                        ) : undefined
                    }
                />

                <ListToolbar
                    baseUrl="/projects"
                    filters={filters}
                    searchPlaceholder="Search projects…"
                    sortOptions={[
                        { value: 'created_at', label: 'Date created' },
                        { value: 'name', label: 'Name' },
                        { value: 'code', label: 'Code' },
                        { value: 'client', label: 'Client' },
                        { value: 'status', label: 'Status' },
                        { value: 'net_budget', label: 'Net Sales Received' },
                    ]}
                />

                <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Code</th>
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Client</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Net Sales Received</th>
                                <th className="px-6 py-3 text-right font-medium">Utilization</th>
                                <th className="px-6 py-3 font-medium">End Date</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={8} className="px-6 py-12 text-center text-slate-500">
                                        <div className="flex flex-col items-center gap-3">
                                            <span>No projects yet.</span>
                                            {canCreate && (
                                                <LinkButton href="/projects/create" size="default">
                                                    Create your first project
                                                </LinkButton>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ) : (
                                rows.map((project) => (
                                    <tr key={project.id} className="hover:bg-slate-50">
                                        <td className="px-6 py-4 font-mono text-slate-900">
                                            {project.code}
                                        </td>
                                        <td className="px-6 py-4 font-medium text-slate-900">
                                            {project.name}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">{project.client}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(project.status)} />
                                        </td>
                                        <td className="px-6 py-4 text-right text-slate-900">
                                            {formatCurrency(project.net_budget)}
                                        </td>
                                        <td className="px-6 py-4 text-right text-slate-600">
                                            {formatPercent(project.utilization_percentage ?? 0)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {formatDate(project.end_date)}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <IconLink
                                                    href={`/projects/${project.id}`}
                                                    icon={Eye}
                                                    label="View project"
                                                />
                                                {canUpdate && (
                                                    <IconLink
                                                        href={`/projects/${project.id}/edit`}
                                                        icon={Pencil}
                                                        label="Edit project"
                                                    />
                                                )}
                                                {canDelete && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="text-red-600 hover:bg-red-50 hover:text-red-700"
                                                        title="Archive project"
                                                        aria-label="Archive project"
                                                        onClick={() => archiveProject(project)}
                                                    >
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={projects} />
                </div>
            </div>
        </AppShell>
    );
}
