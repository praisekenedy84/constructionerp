import ProjectForm from '@/Pages/Projects/ProjectForm';

export default function ProjectsCreate() {
    return (
        <ProjectForm
            mode="create"
            initial={{
                code: '',
                name: '',
                client: '',
                client_phone: '',
                client_tin: '',
                location: '',
                contract_amount: '',
                wht_percentage: '0',
                start_date: '',
                end_date: '',
                status: 'planning',
            }}
        />
    );
}
