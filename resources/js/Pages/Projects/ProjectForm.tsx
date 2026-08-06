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
    client_phone: string;
    client_email: string;
    client_tin: string;
    location: string;
    contract_amount: string;
    wht_percentage: string;
    start_date: string;
    end_date: string;
    status: ProjectStatus;
    recipient_ids: number[];
}

interface ProjectFormProps {
    mode: 'create' | 'edit';
    projectId?: number;
    initial: ProjectFormValues;
    recipients?: Array<{
        id: number;
        name: string;
        phone: string;
        email?: string | null;
        status: string;
    }>;
}

const statusOptions: { value: ProjectStatus; label: string }[] = [
    { value: 'planning', label: 'Planning' },
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On Hold' },
    { value: 'closed', label: 'Closed' },
];

export default function ProjectForm({
    mode,
    projectId,
    initial,
    recipients = [],
}: ProjectFormProps) {
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

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-1 text-sm font-semibold text-slate-900">Client Details</h3>
                        <p className="mb-4 text-sm text-slate-500">
                            These details appear on invoices for this project.
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="client">Client Name</Label>
                                <Input
                                    id="client"
                                    value={data.client}
                                    onChange={(e) => setData('client', e.target.value)}
                                    required
                                />
                                {errors.client && <p className="text-sm text-red-600">{errors.client}</p>}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client_phone">Phone Number</Label>
                                <Input
                                    id="client_phone"
                                    type="tel"
                                    value={data.client_phone}
                                    onChange={(e) => setData('client_phone', e.target.value)}
                                    placeholder="+255 …"
                                    required
                                />
                                {errors.client_phone && (
                                    <p className="text-sm text-red-600">{errors.client_phone}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client_email">Project Email</Label>
                                <Input
                                    id="client_email"
                                    type="email"
                                    value={data.client_email}
                                    onChange={(e) => setData('client_email', e.target.value)}
                                    placeholder="project@client.com"
                                />
                                {errors.client_email && (
                                    <p className="text-sm text-red-600">{errors.client_email}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="client_tin">TIN</Label>
                                <Input
                                    id="client_tin"
                                    value={data.client_tin}
                                    onChange={(e) => setData('client_tin', e.target.value)}
                                    placeholder="Tax Identification Number"
                                    required
                                />
                                {errors.client_tin && (
                                    <p className="text-sm text-red-600">{errors.client_tin}</p>
                                )}
                            </div>
                            <div className="space-y-2 sm:col-span-2">
                                <Label htmlFor="location">Location</Label>
                                <Input
                                    id="location"
                                    value={data.location}
                                    onChange={(e) => setData('location', e.target.value)}
                                    required
                                />
                                {errors.location && (
                                    <p className="text-sm text-red-600">{errors.location}</p>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 className="mb-1 text-sm font-semibold text-slate-900">
                            Project Staff / Recipients
                        </h3>
                        <p className="mb-4 text-sm text-slate-500">
                            Reference list of recipients used on this project. Does not change
                            project operations. Recipients can belong to multiple projects.
                        </p>
                        {recipients.length === 0 ? (
                            <p className="text-sm text-slate-500">
                                No recipients registered yet. Add them under Recipients first.
                            </p>
                        ) : (
                            <div className="max-h-56 space-y-2 overflow-y-auto rounded-md border border-slate-200 p-3">
                                {recipients.map((recipient) => {
                                    const checked = data.recipient_ids.includes(recipient.id);
                                    return (
                                        <label
                                            key={recipient.id}
                                            className="flex items-start gap-3 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                className="mt-1"
                                                checked={checked}
                                                onChange={(e) => {
                                                    const next = e.target.checked
                                                        ? [...data.recipient_ids, recipient.id]
                                                        : data.recipient_ids.filter(
                                                              (id) => id !== recipient.id,
                                                          );
                                                    setData('recipient_ids', next);
                                                }}
                                            />
                                            <span>
                                                <span className="font-medium">{recipient.name}</span>
                                                <span className="block text-xs text-slate-500">
                                                    {recipient.phone}
                                                    {recipient.status !== 'active'
                                                        ? ' · inactive'
                                                        : ''}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                        )}
                        {errors.recipient_ids && (
                            <p className="mt-2 text-sm text-red-600">{errors.recipient_ids}</p>
                        )}
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
