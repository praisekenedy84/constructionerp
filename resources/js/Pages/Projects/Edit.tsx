import ProjectForm, { ProjectFormValues } from '@/Pages/Projects/ProjectForm';
import { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

interface ProjectsEditProps extends PageProps {
    project: ProjectFormValues & { id: number };
    recipients?: Array<{
        id: number;
        name: string;
        phone: string;
        email?: string | null;
        status: string;
    }>;
}

export default function ProjectsEdit() {
    const { project, recipients = [] } = usePage<ProjectsEditProps>().props;

    return (
        <ProjectForm
            mode="edit"
            projectId={project.id}
            recipients={recipients}
            initial={{
                code: project.code,
                name: project.name,
                client: project.client,
                client_phone: project.client_phone,
                client_email: project.client_email ?? '',
                client_tin: project.client_tin,
                location: project.location,
                contract_amount: project.contract_amount,
                wht_percentage: project.wht_percentage,
                start_date: project.start_date,
                end_date: project.end_date,
                status: project.status,
                recipient_ids: project.recipient_ids ?? [],
            }}
        />
    );
}
