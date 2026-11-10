import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';
import { PasswordInput } from '@/Components/ui/password-input';
import { useForm } from '@inertiajs/react';
import { useId, useState } from 'react';

export default function UpdateUserPassword({ tenantId, user }: { tenantId: string; user: { id: number; name: string } }) {
    const [open, setOpen] = useState(false);
    const id = useId();
    const form = useForm({ password: '', password_confirmation: '' });

    function changeOpen(value: boolean) {
        setOpen(value);
        form.reset();
        form.clearErrors();
    }

    return <>
        <Button size="sm" variant="outline" onClick={() => changeOpen(true)}>Update password</Button>
        <Dialog open={open} onOpenChange={changeOpen} title={`Update password for ${user.name}`}>
            <form className="mt-4 space-y-4" onSubmit={event => {
                event.preventDefault();
                form.put(`/platform/tenants/${tenantId}/users/${user.id}/password`, {
                    preserveScroll: true,
                    onSuccess: () => changeOpen(false),
                    onFinish: () => form.reset(),
                });
            }}>
                {(['password', 'password_confirmation'] as const).map(field => <div key={field} className="space-y-2">
                    <Label htmlFor={`${id}-${field}`}>{field === 'password' ? 'New password' : 'Confirm new password'}</Label>
                    <PasswordInput id={`${id}-${field}`} autoComplete="new-password" required value={form.data[field]} onChange={event => form.setData(field, event.target.value)} />
                    {form.errors[field] && <p className="text-sm text-red-600">{form.errors[field]}</p>}
                </div>)}
                <div className="flex gap-2">
                    <Button type="submit" disabled={form.processing}>Update password</Button>
                    <Button type="button" variant="outline" disabled={form.processing} onClick={() => changeOpen(false)}>Cancel</Button>
                </div>
            </form>
        </Dialog>
    </>;
}
