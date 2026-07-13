import AppShell from '@/Components/Layout/AppShell';
import BoqTree from '@/Components/Domain/BoqTree';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { PageProps, BoqSection, Project } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Upload } from 'lucide-react';

interface BoqIndexProps extends PageProps {
    project: Project;
    sections: BoqSection[];
}

export default function BoqIndex() {
    const { project, sections } = usePage<BoqIndexProps>().props;

    return (
        <AppShell title="Bill of Quantities">
            <Head title={`BOQ — ${project.name}`} />
            <div className="space-y-6">
                <PageHeader
                    title="Bill of Quantities"
                    description={`${project.code} — ${project.name}`}
                    actions={
                        <Link href={`/projects/${project.id}/boq/import`}>
                            <Button>
                                <Upload className="h-4 w-4" />
                                Import BOQ
                            </Button>
                        </Link>
                    }
                />

                <DataPanel title="BOQ Tree" noPadding>
                    <BoqTree sections={sections} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
