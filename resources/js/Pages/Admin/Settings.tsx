import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import PageHeader from '@/Components/Shared/PageHeader';
import AdminNav from '@/Components/Admin/AdminNav';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { PageProps, UiSettings } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface AdminSettingsProps extends PageProps {
    ui_settings: UiSettings;
}

export default function AdminSettings() {
    const { ui_settings } = usePage<AdminSettingsProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        app_name: ui_settings.app_name,
        tagline: ui_settings.tagline,
        company_address: ui_settings.company_address ?? '',
        company_contact: ui_settings.company_contact ?? '',
        company_logo_url: ui_settings.company_logo_url ?? '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/admin/settings/ui');
    }

    return (
        <AppShell title="Settings">
            <Head title="Settings" />
            <div className="mx-auto max-w-2xl space-y-6">
                <PageHeader
                    title="Tenant branding"
                    description="Application name and tagline shown in the sidebar."
                />
                <AdminNav active="settings" />

                <form onSubmit={submit}>
                    <DataPanel title="Branding">
                        <div className="space-y-4">
                            <div className="space-y-2">
                                <Label>App Name</Label>
                                <Input
                                    value={data.app_name}
                                    onChange={(e) => setData('app_name', e.target.value)}
                                    required
                                />
                                {errors.app_name && (
                                    <p className="text-sm text-red-600">{errors.app_name}</p>
                                )}
                            </div>
                            <div className="space-y-2">
                                <Label>Tagline</Label>
                                <Input
                                    value={data.tagline}
                                    onChange={(e) => setData('tagline', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Company Address</Label>
                                <Input
                                    value={data.company_address}
                                    onChange={(e) => setData('company_address', e.target.value)}
                                    placeholder="Postal or physical address shown on invoices"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Company Contact</Label>
                                <Input
                                    value={data.company_contact}
                                    onChange={(e) => setData('company_contact', e.target.value)}
                                    placeholder="Phone, email, or website"
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Company Logo URL</Label>
                                <Input
                                    value={data.company_logo_url}
                                    onChange={(e) => setData('company_logo_url', e.target.value)}
                                    placeholder="HTTPS URL or /storage/... path"
                                />
                            </div>
                            <Button type="submit" disabled={processing}>
                                {processing ? 'Saving…' : 'Save Settings'}
                            </Button>
                        </div>
                    </DataPanel>
                </form>
            </div>
        </AppShell>
    );
}
