import TenantUsersPanel, { TenantUsersPanelProps } from '@/Components/Admin/TenantUsersPanel';
import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useState } from 'react';

interface TenantDetail {
    id: string;
    name: string;
    slug: string;
    status: string;
    suspended_at: string | null;
    suspended_reason: string | null;
    created_at: string | null;
    total_users: number;
    locked_users: number;
}

interface PlatformTenantShowProps extends PageProps, TenantUsersPanelProps {
    tenant: TenantDetail;
}

export default function PlatformTenantShow(props: PlatformTenantShowProps) {
    const { tenant } = props;
    const [showSuspendForm, setShowSuspendForm] = useState(false);
    const suspendForm = useForm({ reason: '' });
    const detailsForm = useForm({ name: tenant.name, slug: tenant.slug });
    const [showDeleteForm, setShowDeleteForm] = useState(false);
    const deleteForm = useForm({ confirmation: '' });

    function submitSuspend(e: FormEvent) {
        e.preventDefault();
        suspendForm.post(`/platform/tenants/${tenant.id}/suspend`, {
            onSuccess: () => setShowSuspendForm(false),
        });
    }

    function reactivate() {
        if (!confirm(`Reactivate "${tenant.name}"?`)) return;
        router.post(`/platform/tenants/${tenant.id}/reactivate`);
    }

    return (
        <PlatformShell title={tenant.name}>
            <Head title={tenant.name} />
            <div className="space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <div className="flex items-center gap-3">
                            <h2 className="text-2xl font-bold text-slate-900 dark:text-white">{tenant.name}</h2>
                            <StatusBadge status={tenant.status} />
                        </div>
                        <p className="mt-1 text-sm text-slate-500 dark:text-slate-400">{tenant.slug}</p>
                    </div>

                </div>

                <DataPanel title="Tenant details">
                    <form onSubmit={event => {
                        event.preventDefault();
                        detailsForm.patch(`/platform/tenants/${tenant.id}`, { preserveScroll: true });
                    }} className="max-w-lg space-y-4">
                        <div className="space-y-2">
                            <Label htmlFor="tenant-name">Company name</Label>
                            <Input id="tenant-name" value={detailsForm.data.name} onChange={event => detailsForm.setData('name', event.target.value)} required maxLength={255} />
                            {detailsForm.errors.name && <p className="text-sm text-red-600">{detailsForm.errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="tenant-slug">Company slug</Label>
                            <Input id="tenant-slug" value={detailsForm.data.slug} onChange={event => detailsForm.setData('slug', event.target.value)} required maxLength={100} />
                            <p className="text-sm text-slate-500">Use lowercase letters, numbers, and single hyphens.</p>
                            {detailsForm.errors.slug && <p className="text-sm text-red-600">{detailsForm.errors.slug}</p>}
                        </div>
                        <Button type="submit" disabled={detailsForm.processing || !detailsForm.isDirty}>Save tenant details</Button>
                    </form>
                </DataPanel>

                <DataPanel title="Tenant control">
                    {tenant.status === 'active' ? (
                        <div className="space-y-4">
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                Suspending a tenant blocks all users from signing in and ends active
                                sessions on their next request.
                            </p>
                            {showSuspendForm ? (
                                <form onSubmit={submitSuspend} className="max-w-md space-y-3">
                                    <div className="space-y-2">
                                        <Label>Reason (optional)</Label>
                                        <Input
                                            value={suspendForm.data.reason}
                                            onChange={(e) => suspendForm.setData('reason', e.target.value)}
                                        />
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="border-red-800 text-red-400 hover:bg-red-950"
                                            disabled={suspendForm.processing}
                                        >
                                            Confirm suspend
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setShowSuspendForm(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            ) : (
                                <Button
                                    variant="outline"
                                    className="border-red-800 text-red-400 hover:bg-red-950"
                                    onClick={() => setShowSuspendForm(true)}
                                >
                                    Suspend tenant
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {tenant.suspended_reason && (
                                <p className="text-sm text-slate-500 dark:text-slate-400">
                                    Reason: {tenant.suspended_reason}
                                </p>
                            )}
                            {tenant.suspended_at && (
                                <p className="text-sm text-slate-500">
                                    Suspended on{' '}
                                    {new Date(tenant.suspended_at).toLocaleString()}
                                </p>
                            )}
                            <Button
                                className="bg-green-700 hover:bg-green-600"
                                onClick={reactivate}
                            >
                                Reactivate tenant
                            </Button>
                        </div>
                    )}
                </DataPanel>

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Total users</p>
                        <p className="mt-1 text-2xl font-bold text-slate-900 dark:text-white">{tenant.total_users}</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Locked users</p>
                        <p className="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{tenant.locked_users}</p>
                    </div>
                    <div className="rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900">
                        <p className="text-sm text-slate-500 dark:text-slate-400">Created</p>
                        <p className="mt-1 text-sm text-slate-900 dark:text-white">
                            {tenant.created_at
                                ? new Date(tenant.created_at).toLocaleDateString()
                                : '—'}
                        </p>
                    </div>
                </div>

                <TenantUsersPanel {...props} />

                <DataPanel title="Delete tenant">
                    <div className="max-w-lg space-y-4">
                        <p className="text-sm text-red-600 dark:text-red-400">Permanently delete this tenant, its database, user accounts, and company-access grants. This cannot be undone.</p>
                        {showDeleteForm ? (
                            <form onSubmit={event => {
                                event.preventDefault();
                                deleteForm.delete(`/platform/tenants/${tenant.id}`, { preserveScroll: true });
                            }} className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="delete-tenant-confirmation">Type {tenant.slug} to confirm</Label>
                                    <Input id="delete-tenant-confirmation" autoComplete="off" value={deleteForm.data.confirmation} onChange={event => deleteForm.setData('confirmation', event.target.value)} required />
                                    {deleteForm.errors.confirmation && <p className="text-sm text-red-600">{deleteForm.errors.confirmation}</p>}
                                </div>
                                <div className="flex gap-2">
                                    <Button type="submit" className="bg-red-700 text-white hover:bg-red-800" disabled={deleteForm.processing || deleteForm.data.confirmation !== tenant.slug}>Permanently delete tenant</Button>
                                    <Button type="button" variant="outline" disabled={deleteForm.processing} onClick={() => { setShowDeleteForm(false); deleteForm.reset(); deleteForm.clearErrors(); }}>Cancel</Button>
                                </div>
                            </form>
                        ) : <Button className="bg-red-700 text-white hover:bg-red-800" onClick={() => setShowDeleteForm(true)}>Delete tenant</Button>}
                    </div>
                </DataPanel>


            </div>
        </PlatformShell>
    );
}
