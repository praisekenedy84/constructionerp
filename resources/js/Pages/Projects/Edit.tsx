import ProjectForm, { mergeComplianceRules, ProjectFormValues } from '@/Pages/Projects/ProjectForm';
import { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

interface ProjectsEditProps extends PageProps {
    project: ProjectFormValues & { id: number };
}

export default function ProjectsEdit() {
    const { project } = usePage<ProjectsEditProps>().props;

    return (
        <ProjectForm
            mode="edit"
            projectId={project.id}
            initial={{
                code: project.code,
                name: project.name,
                client: project.client,
                location: project.location,
                contract_amount: project.contract_amount,
                wht_percentage: project.wht_percentage,
                start_date: project.start_date,
                end_date: project.end_date,
                status: project.status,
                compliance_rules: mergeComplianceRules(project.compliance_rules),
            }}
        />
    );
}
