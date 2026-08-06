import ProjectForm from '@/Pages/Projects/ProjectForm';
import { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';

interface ProjectsCreateProps extends PageProps {
    recipients?: Array<{
        id: number;
        name: string;
        phone: string;
        email?: string | null;
        status: string;
    }>;
}

export default function ProjectsCreate() {
    const { recipients = [] } = usePage<ProjectsCreateProps>().props;

    return (
        <ProjectForm
            mode="create"
            recipients={recipients}
            initial={{
                code: '',
                name: '',
                client: '',
                client_phone: '',
                client_email: '',
                client_tin: '',
                location: '',
                contract_amount: '',
                wht_percentage: '0',
                start_date: '',
                end_date: '',
                status: 'planning',
                recipient_ids: [],
            }}
        />
    );
}
