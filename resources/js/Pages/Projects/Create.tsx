import {
    AvailableComplianceRule,
    emptyComplianceItem,
} from '@/Components/Domain/ComplianceItemsEditor';
import ProjectForm from '@/Pages/Projects/ProjectForm';
import { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

interface ProjectsCreateProps extends PageProps {
    available_rules: AvailableComplianceRule[];
}

export default function ProjectsCreate() {
    const { available_rules } = usePage<ProjectsCreateProps>().props;

    return (
        <ProjectForm
            mode="create"
            availableRules={available_rules ?? []}
            initial={{
                code: '',
                name: '',
                client: '',
                location: '',
                contract_amount: '',
                wht_percentage: '0',
                start_date: '',
                end_date: '',
                status: 'planning',
                ipcs: [{ compliance_items: [emptyComplianceItem()] }],
            }}
        />
    );
}
