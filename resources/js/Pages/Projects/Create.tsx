import ProjectForm, { defaultComplianceRules } from '@/Pages/Projects/ProjectForm';

export default function ProjectsCreate() {
    return (
        <ProjectForm
            mode="create"
            initial={{
                code: '',
                name: '',
                client: '',
                location: '',
                contract_amount: '',
                wht_percentage: '5',
                start_date: '',
                end_date: '',
                status: 'planning',
                compliance_rules: defaultComplianceRules(),
            }}
        />
    );
}
