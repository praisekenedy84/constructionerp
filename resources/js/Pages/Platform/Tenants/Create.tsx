import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import { PageProps } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEvent } from 'react';

export default function PlatformTenantsCreate() {
    const form = useForm({
        name: '',
        slug: '',
        admin_name: '',
        admin_email: '',
        admin_password: '',
        admin_password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        form.post('/platform/tenants');
    }

    return (
        <PlatformShell title="Add Tenant">
            <Head title="Add Tenant" />
            <div className="space-y-6">
                <PageHeader
                    title="Provision new tenant"
                    description="Create a new company with its own isolated database and system administrator."
                />

                <DataPanel title="Company details">
                    <form onSubmit={submit} className="grid max-w-2xl gap-4">
                        <div className="space-y-2">
                            <Label>Company name</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                required
                            />
                            {form.errors.name && <p className="text-sm text-red-600 dark:text-red-400">{form.errors.name}</p>}
                        </div>
                        <div className="space-y-2">
                            <Label>Slug</Label>
                            <Input
                                value={form.data.slug}
                                onChange={(e) => form.setData('slug', e.target.value)}
                                placeholder="acme-construction"
                                required
                            />
                            {form.errors.slug && <p className="text-sm text-red-600 dark:text-red-400">{form.errors.slug}</p>}
                        </div>

                        <hr className="border-slate-200 dark:border-slate-800" />

                        <div className="space-y-2">
                            <Label>Administrator name</Label>
                            <Input
                                value={form.data.admin_name}
                                onChange={(e) => form.setData('admin_name', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Administrator email</Label>
                            <Input
                                type="email"
                                value={form.data.admin_email}
                                onChange={(e) => form.setData('admin_email', e.target.value)}
                                required
                            />
                            {form.errors.admin_email && (
                                <p className="text-sm text-red-600 dark:text-red-400">{form.errors.admin_email}</p>
                            )}
                        </div>
                        <div className="space-y-2">
                            <Label>Administrator password</Label>
                            <PasswordInput
                                value={form.data.admin_password}
                                onChange={(e) => form.setData('admin_password', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Confirm password</Label>
                            <PasswordInput
                                value={form.data.admin_password_confirmation}
                                onChange={(e) =>
                                    form.setData('admin_password_confirmation', e.target.value)
                                }
                                required
                            />
                            {form.errors.admin_password && (
                                <p className="text-sm text-red-600 dark:text-red-400">{form.errors.admin_password}</p>
                            )}
                        </div>

                        <Button
                            type="submit"
                            className="w-fit bg-violet-600 hover:bg-violet-700"
                            disabled={form.processing}
                        >
                            Provision tenant
                        </Button>
                    </form>
                </DataPanel>
            </div>
        </PlatformShell>
    );
}
