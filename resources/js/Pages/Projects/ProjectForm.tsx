import AppShell from '@/Components/Layout/AppShell';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { ProjectStatus } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export interface ProjectFormValues {
    code: string;
    name: string;
    client: string;
    location: string;
    contract_amount: string;
    wht_percentage: string;
    start_date: string;
    end_date: string;
    status: ProjectStatus;
}

interface ProjectFormProps {
    mode: 'create' | 'edit';
    projectId?: number;
    initial: ProjectFormValues;
}

const statusOptions: { value: ProjectStatus; label: string }[] = [
    { value: 'planning', label: 'Planning' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'closed', label: 'Closed' },
];

export default function ProjectForm({ mode, projectId, initial }: ProjectFormProps) {
    const { data, setData, post, put, processing, errors } = useForm(initial);

    const title = mode === 'create' ? 'Create Project' : 'Edit Project';
    const headTitle = mode === 'create' ? 'New Project' : `Edit ${data.code || 'Project'}`;

    function submit(e: FormEvent) {
        e.preventDefault();
        if (mode === 'create') {
            post('/projects');
            return;
        }
        put(`/projects/${projectId}`);
    }

    return (
        <AppShell title={headTitle}>
            <Head title={headTitle} />
            <div className="mx-auto max-w-3xl space-y-6">
                <PageHeader
                    title={title}
                    description={
                        mode === 'create'
                            ? 'Enter project details only. Client disbursement phases and IPCs can be added later from the project page — after compliance setup or once acquisitions have started.'
                            : 'Update project details. Manage phases and IPCs from the project page.'
                    }
                />

                <form onSubmit={submit} className="space-y-6">
                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-4 text-sm font-semibold text-slate-900">Project Details</h3>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="code">Project Code</Label>
                                <Input
                                    id="code"
                                    value={data.code}
                                    onChange={(e) => setData('code', e.target.value)}
                                    required
                                />
                                {errors.code && <p className="text-sm text-red-600">{errors.code}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="name">Project Name</Label>
                                <Input
                                    id="name"
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    required
                                />
                                {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client">Client</Label>
                                <Input
                                    id="client"
                                    value={data.client}
                                    onChange={(e) => setData('client', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="contract_amount">Contract Amount (TZS)</Label>
                                <AmountInput
                                    id="contract_amount"
                                    value={data.contract_amount}
                                    onValueChange={(v) => setData('contract_amount', v)}
                                    required
                                />
                                {errors.contract_amount && (
                                    <p className="text-sm text-red-600">{errors.contract_amount}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="status">Status</Label>
                                <select
                                    id="status"
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                                    value={data.status}
                                    onChange={(e) =>
                                        setData('status', e.target.value as ProjectStatus)
                                    }
                                >
                                    {statusOptions.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="start_date">Start Date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    value={data.start_date}
                                    onChange={(e) => setData('start_date', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="end_date">End Date</Label>
                                <Input
                                    id="end_date"
                                    type="date"
                                    value={data.end_date}
                                    onChange={(e) => setData('end_date', e.target.value)}
                                    required
                                />
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href={
                                mode === 'edit' && projectId
                                    ? `/projects/${projectId}`
                                    : '/projects'
                            }
                        >
                            <Button type="button" variant="outline">
                                Cancel
                            </Button>
                        </Link>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? mode === 'create'
                                    ? 'Creating…'
                                    : 'Saving…'
                                : mode === 'create'
                                  ? 'Create Project'
                                  : 'Save Changes'}
                        </Button>
                    </div>
                </form>
            </div>
        </AppShell>
    );
}
