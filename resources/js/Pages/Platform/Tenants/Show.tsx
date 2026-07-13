import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
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

interface PlatformTenantShowProps extends PageProps {
    tenant: TenantDetail;
}

export default function PlatformTenantShow({ tenant }: PlatformTenantShowProps) {
    const [showSuspendForm, setShowSuspendForm] = useState(false);
    const suspendForm = useForm({ reason: '' });

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
                    <Link href={`/platform/tenants/${tenant.id}/users`}>
                        <Button className="bg-violet-600 hover:bg-violet-700">Manage users</Button>
                    </Link>
                </div>

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
            </div>
        </PlatformShell>
    );
}
