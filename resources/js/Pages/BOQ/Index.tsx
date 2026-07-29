import AppShell from '@/Components/Layout/AppShell';
import BoqTree from '@/Components/Domain/BoqTree';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { hasPermission } from '@/lib/permissions';
import { PageProps, BoqSection, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Download, Plus, Upload } from 'lucide-react';

interface BoqIndexProps extends PageProps {
    project: Project;
    sections: BoqSection[];
}

export default function BoqIndex() {
    const { project, sections, auth } = usePage<BoqIndexProps>().props;
    const canCreate = hasPermission(auth.user, 'boq', 'create');
    const canImport = hasPermission(auth.user, 'boq', 'import');
    const canRead = hasPermission(auth.user, 'boq', 'read');
    const canUpdate = hasPermission(auth.user, 'boq', 'update');

    return (
        <AppShell title="Bill of Quantities">
            <Head title={`BOQ — ${project.name}`} />
            <div className="space-y-6">
                <PageHeader
                    title="Bill of Quantities"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canCreate && (
                                <Link href={`/projects/${project.id}/boq/create`}>
                                    <Button>
                                        <Plus className="h-4 w-4" />
                                        Add Items
                                    </Button>
                                </Link>
                            )}
                            {canImport && (
                                <Link href={`/projects/${project.id}/boq/import`}>
                                    <Button variant="outline">
                                        <Upload className="h-4 w-4" />
                                        Import
                                    </Button>
                                </Link>
                            )}
                            {canRead && (
                                <a href={`/projects/${project.id}/boq/export`}>
                                    <Button variant="outline">
                                        <Download className="h-4 w-4" />
                                        Export Excel
                                    </Button>
                                </a>
                            )}
                        </div>
                    }
                />

                <DataPanel title="BOQ Tree" noPadding>
                    <BoqTree
                        sections={sections}
                        projectId={project.id}
                        canUpdate={canUpdate}
                    />
                </DataPanel>
            </div>
        </AppShell>
    );
}
