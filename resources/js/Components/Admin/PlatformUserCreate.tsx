import { useForm } from '@inertiajs/react';
import { useState, FormEvent } from 'react';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Dialog } from '@/Components/ui/dialog';
import { Label } from '@/Components/ui/label';

interface EditableUser { id: number; name: string; username: string; email: string; phone: string | null; role: string }
export default function PlatformUserCreate({ tenantId, roles, user }: { tenantId: string; roles: string[]; user?: EditableUser }) {
    const [open, setOpen] = useState(false);
    const initial = () => ({ name: user?.name ?? '', username: user?.username ?? '', email: user?.email ?? '', phone: user?.phone ?? '', password: '', password_confirmation: '', role: user?.role ?? roles[0] ?? '' });
    const form = useForm(initial());
    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => { form.reset(); setOpen(false); },
            onFinish: () => form.setData(data => ({ ...data, password: '', password_confirmation: '' })),
        };
        if (user) form.patch(`/platform/tenants/${tenantId}/users/${user.id}`, options);
        else form.post(`/platform/tenants/${tenantId}/users`, options);
    }
    return <section className={user ? '' : 'rounded-lg border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'}>
        <Button size={user ? 'sm' : 'default'} variant={user ? 'outline' : 'default'} onClick={() => { form.setData(initial()); form.clearErrors(); setOpen(true); }}>{user ? 'Edit user / password' : 'Add user'}</Button>
        <Dialog open={open} onOpenChange={setOpen} title={user ? `Edit ${user.name}` : 'Add user'}><form onSubmit={submit} className="mt-4 grid gap-4 sm:grid-cols-2">
            <p className="text-sm text-slate-500 sm:col-span-2">{user ? 'Update this account. Leave the password blank to keep the current password.' : 'Create a login account in this company. Read-only access to other companies can be assigned after creation.'}</p>
            {(['name', 'username', 'email', 'phone'] as const).map(field => <div key={field} className="space-y-2">
                <Label htmlFor={`platform-user-${field}`}>{field === 'phone' ? 'Phone (optional)' : field.charAt(0).toUpperCase() + field.slice(1)}</Label>
                <Input id={`platform-user-${field}`} type={field === 'email' ? 'email' : field === 'phone' ? 'tel' : 'text'} autoComplete={field === 'username' ? 'username' : undefined} value={form.data[field]} onChange={event => form.setData(field, event.target.value)} required={field !== 'phone'} maxLength={field === 'phone' ? 50 : 255} />
                {form.errors[field] && <p className="text-sm text-red-600">{form.errors[field]}</p>}
            </div>)}
            <div className="space-y-2"><Label htmlFor="platform-user-role">Role</Label>
                <select id="platform-user-role" className="w-full rounded border bg-transparent p-2" value={form.data.role} onChange={event => form.setData('role', event.target.value)} required>
                    {roles.map(role => <option key={role} value={role}>{role}</option>)}
                </select>{form.errors.role && <p className="text-sm text-red-600">{form.errors.role}</p>}
            </div>
            {(['password', 'password_confirmation'] as const).map(field => <div key={field} className="space-y-2">
                <Label htmlFor={`platform-user-${field}`}>{field === 'password' ? (user ? 'New password (optional)' : 'Password') : 'Confirm password'}</Label>
                <PasswordInput id={`platform-user-${field}`} autoComplete="new-password" value={form.data[field]} onChange={event => form.setData(field, event.target.value)} required={!user} />
                {form.errors[field] && <p className="text-sm text-red-600">{form.errors[field]}</p>}
            </div>)}
            <div className="sm:col-span-2"><Button type="submit" disabled={form.processing}>{user ? 'Save changes' : 'Create user'}</Button></div>
        </form></Dialog>
    </section>;
}
