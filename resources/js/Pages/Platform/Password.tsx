import PlatformShell from '@/Components/Layout/PlatformShell';
import DataPanel from '@/Components/Shared/DataPanel';
import { Button } from '@/Components/ui/button';
import { Label } from '@/Components/ui/label';
import { PasswordInput } from '@/Components/ui/password-input';
import { Head, useForm } from '@inertiajs/react';

export default function PlatformPassword() {
    const form = useForm({ current_password: '', password: '', password_confirmation: '' });
    const labels = { current_password: 'Current password', password: 'New password', password_confirmation: 'Confirm new password' };

    return <PlatformShell title="Change password">
        <Head title="Change password" />
        <DataPanel title="Change your platform password">
            <form className="max-w-md space-y-4" onSubmit={event => {
                event.preventDefault();
                form.put('/platform/password', { preserveScroll: true, onFinish: () => form.reset() });
            }}>
                {(Object.keys(labels) as (keyof typeof labels)[]).map(field => <div key={field} className="space-y-2">
                    <Label htmlFor={field}>{labels[field]}</Label>
                    <PasswordInput id={field} autoComplete={field === 'current_password' ? 'current-password' : 'new-password'} value={form.data[field]} onChange={event => form.setData(field, event.target.value)} required />
                    {form.errors[field] && <p className="text-sm text-red-600">{form.errors[field]}</p>}
                </div>)}
                {form.recentlySuccessful && <p role="status" className="text-sm text-green-700 dark:text-green-400">Password updated.</p>}
                <Button type="submit" disabled={form.processing}>Update password</Button>
            </form>
        </DataPanel>
    </PlatformShell>;
}
