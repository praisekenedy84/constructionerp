import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import AdminNav from '@/Components/Admin/AdminNav';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { PasswordInput } from '@/Components/ui/password-input';
import { Label } from '@/Components/ui/label';
import { ListingFilters, PageProps, Paginated, User } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { Plus, UserCheck } from 'lucide-react';

interface AdminUsersProps extends PageProps {
    users: Paginated<User>;
    filters: ListingFilters;
    assignable_roles: string[];
}

export default function AdminUsers() {
    const { users, filters, assignable_roles, auth } = usePage<AdminUsersProps>().props;
    const rows = users.data ?? [];
    const [editingId, setEditingId] = useState<number | null>(null);
    const [createOpen, setCreateOpen] = useState(false);

    const createForm = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: assignable_roles[0] ?? 'Site Engineer',
    });

    const editForm = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
    });

    function openCreateDialog() {
        createForm.clearErrors();
        setCreateOpen(true);
    }

    function closeCreateDialog() {
        if (!confirmDiscardIfDirty(createForm.isDirty)) {
            return;
        }
        setCreateOpen(false);
        createForm.reset();
        createForm.clearErrors();
    }

    function submitCreate(e: FormEvent) {
        e.preventDefault();
        createForm.post('/admin/users', {
            onSuccess: () => {
                createForm.reset();
                setCreateOpen(false);
            },
        });
    }

    function startEdit(user: User) {
        setEditingId(user.id);
        editForm.setData({
            name: user.name,
            email: user.email,
            password: '',
            password_confirmation: '',
            role: user.roles[0] ?? assignable_roles[0],
        });
    }

    function submitEdit(e: FormEvent) {
        e.preventDefault();
        if (!editingId) return;
        editForm.patch(`/admin/users/${editingId}`, {
            onSuccess: () => setEditingId(null),
        });
    }

    function removeUser(user: User) {
        if (!confirm(`Remove ${user.name} from your organization?`)) return;
        router.delete(`/admin/users/${user.id}`);
    }

    function impersonateUser(user: User) {
        if (!confirm(`Impersonate ${user.name}?`)) return;
        router.post(`/auth/impersonate/${user.id}`);
    }

    return (
        <AppShell title="User Management">
            <Head title="Users" />
            <div className="space-y-6">
                <PageHeader
                    title="User Management"
                    description="Add, edit, and remove login accounts for your company. Assign roles to control what each person can do."
                    actions={
                        <Button onClick={openCreateDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add User
                        </Button>
                    }
                />
                <AdminNav active="users" />

                {editingId && (
                    <DataPanel title="Edit user">
                        <form onSubmit={submitEdit} className="grid gap-4 sm:grid-cols-2">
                            <div className="space-y-2">
                                <Label>Name</Label>
                                <Input
                                    value={editForm.data.name}
                                    onChange={(e) => editForm.setData('name', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Email</Label>
                                <Input
                                    type="email"
                                    value={editForm.data.email}
                                    onChange={(e) => editForm.setData('email', e.target.value)}
                                    required
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>New password (optional)</Label>
                                <PasswordInput
                                    value={editForm.data.password}
                                    onChange={(e) => editForm.setData('password', e.target.value)}
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Confirm new password</Label>
                                <PasswordInput
                                    value={editForm.data.password_confirmation}
                                    onChange={(e) =>
                                        editForm.setData('password_confirmation', e.target.value)
                                    }
                                />
                            </div>
                            <div className="space-y-2">
                                <Label>Role</Label>
                                <select
                                    className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                                    value={editForm.data.role}
                                    onChange={(e) => editForm.setData('role', e.target.value)}
                                >
                                    {assignable_roles.map((r) => (
                                        <option key={r} value={r}>
                                            {r}
                                        </option>
                                    ))}
                                </select>
                            </div>
                            <div className="flex items-end gap-2">
                                <Button type="submit" disabled={editForm.processing}>
                                    Save changes
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setEditingId(null)}
                                >
                                    Cancel
                                </Button>
                            </div>
                        </form>
                    </DataPanel>
                )}

                <ListToolbar
                    baseUrl="/admin/users"
                    filters={filters}
                    searchPlaceholder="Search name, email…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'email', label: 'Email' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Company users" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Email</th>
                                <th className="px-6 py-3 font-medium">Role</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-6 py-4 font-medium">
                                        {user.name}
                                        {user.is_self && (
                                            <span className="ml-2 text-xs text-slate-400">(you)</span>
                                        )}
                                    </td>
                                    <td className="px-6 py-4 text-slate-600">{user.email}</td>
                                    <td className="px-6 py-4 text-slate-600">
                                        {user.roles.join(', ')}
                                    </td>
                                    <td className="px-6 py-4 text-right">
                                        <div className="flex justify-end gap-2">
                                            {auth.user?.can_impersonate &&
                                                !user.is_self &&
                                                !user.roles.includes('Platform Admin') && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => impersonateUser(user)}
                                                    >
                                                        <UserCheck className="mr-1 h-3 w-3" />
                                                        Impersonate
                                                    </Button>
                                                )}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => startEdit(user)}
                                            >
                                                Edit
                                            </Button>
                                            {!user.is_self && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="border-red-200 text-red-700"
                                                    onClick={() => removeUser(user)}
                                                >
                                                    Remove
                                                </Button>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={users} />
                </DataPanel>
            </div>

            <Dialog
                open={createOpen}
                onOpenChange={(next) => (next ? openCreateDialog() : closeCreateDialog())}
                title="Add User"
                description="Create a login account and assign a role."
            >
                <form onSubmit={submitCreate} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="user-name">Name</Label>
                        <Input
                            id="user-name"
                            value={createForm.data.name}
                            onChange={(e) => createForm.setData('name', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="user-email">Email</Label>
                        <Input
                            id="user-email"
                            type="email"
                            value={createForm.data.email}
                            onChange={(e) => createForm.setData('email', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="user-password">Password</Label>
                        <PasswordInput
                            id="user-password"
                            value={createForm.data.password}
                            onChange={(e) => createForm.setData('password', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="user-password-confirm">Confirm password</Label>
                        <PasswordInput
                            id="user-password-confirm"
                            value={createForm.data.password_confirmation}
                            onChange={(e) =>
                                createForm.setData('password_confirmation', e.target.value)
                            }
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="user-role">Role</Label>
                        <select
                            id="user-role"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 dark:border-slate-700 dark:bg-slate-800 dark:text-white"
                            value={createForm.data.role}
                            onChange={(e) => createForm.setData('role', e.target.value)}
                        >
                            {assignable_roles.map((r) => (
                                <option key={r} value={r}>
                                    {r}
                                </option>
                            ))}
                        </select>
                    </div>
                    <DialogFormActions
                        onCancel={closeCreateDialog}
                        processing={createForm.processing}
                        submitLabel="Add user"
                        processingLabel="Adding…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
