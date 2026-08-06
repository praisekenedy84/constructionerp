import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { hasPermission } from '@/lib/permissions';
import { ListingFilters, PageProps, Paginated, Recipient } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface RecipientsIndexProps extends PageProps {
    recipients: Paginated<Recipient>;
    filters: ListingFilters & { status?: string };
}

type FormMode = 'create' | 'edit';

const emptyForm = {
    name: '',
    phone: '',
    email: '',
    address: '',
    national_id: '',
    status: 'active' as 'active' | 'inactive',
};

export default function RecipientsIndex() {
    const { recipients, filters, auth } = usePage<RecipientsIndexProps>().props;
    const rows = recipients.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<FormMode>('create');
    const [editingId, setEditingId] = useState<number | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } =
        useForm(emptyForm);

    function openCreate() {
        clearErrors();
        reset();
        setMode('create');
        setEditingId(null);
        setData(emptyForm);
        setOpen(true);
    }

    function openEdit(recipient: Recipient) {
        clearErrors();
        setMode('edit');
        setEditingId(recipient.id);
        setData({
            name: recipient.name,
            phone: recipient.phone,
            email: recipient.email ?? '',
            address: recipient.address ?? '',
            national_id: recipient.national_id ?? '',
            status: recipient.status,
        });
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset();
        clearErrors();
        setEditingId(null);
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        if (mode === 'create') {
            post('/recipients', {
                onSuccess: () => {
                    reset();
                    setOpen(false);
                },
            });
            return;
        }

        if (editingId == null) {
            return;
        }

        put(`/recipients/${editingId}`, {
            onSuccess: () => {
                reset();
                setOpen(false);
                setEditingId(null);
            },
        });
    }

    function remove(recipient: Recipient) {
        if (!confirm(`Archive or deactivate ${recipient.name}?`)) {
            return;
        }
        router.delete(`/recipients/${recipient.id}`);
    }

    return (
        <AppShell title="Recipients">
            <Head title="Recipients" />
            <div className="space-y-6">
                <PageHeader
                    title="Recipients"
                    description="Register recipients before using them on requisitions and projects."
                    actions={
                        canCreate ? (
                            <Button onClick={openCreate}>
                                <Plus className="mr-2 h-4 w-4" />
                                Add Recipient
                            </Button>
                        ) : undefined
                    }
                />

                <ListToolbar
                    baseUrl="/recipients"
                    filters={filters}
                    searchPlaceholder="Search name, phone, email, ID…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'phone', label: 'Phone' },
                        { value: 'email', label: 'Email' },
                        { value: 'status', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'status',
                            label: 'Status',
                            emptyLabel: 'All statuses',
                            options: [
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                            ],
                        },
                    ]}
                />

                <DataPanel title="Recipient Directory" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Phone</th>
                                <th className="px-6 py-3 font-medium">Email</th>
                                <th className="px-6 py-3 font-medium">Address</th>
                                <th className="px-6 py-3 font-medium">National / Employee ID</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                {canUpdate && (
                                    <th className="px-6 py-3 text-right font-medium">Actions</th>
                                )}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={canUpdate ? 7 : 6}
                                        className="px-6 py-12 text-center text-slate-500"
                                    >
                                        No recipients found. Register recipients before creating
                                        requisitions.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((recipient) => (
                                    <tr key={recipient.id}>
                                        <td className="px-6 py-4 font-medium">{recipient.name}</td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {recipient.phone}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {recipient.email ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {recipient.address ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {recipient.national_id ?? '—'}
                                        </td>
                                        <td className="px-6 py-4 capitalize text-slate-600">
                                            {recipient.status}
                                        </td>
                                        {canUpdate && (
                                            <td className="px-6 py-4 text-right">
                                                <div className="flex justify-end gap-2">
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => openEdit(recipient)}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => remove(recipient)}
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </Button>
                                                </div>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={recipients} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? (mode === 'create' ? openCreate() : undefined) : closeDialog())}
                title={mode === 'create' ? 'Register Recipient' : 'Edit Recipient'}
                description="Phone number is required. Recipients must be registered before use on requisitions."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="recipient-name">Full Name</Label>
                        <Input
                            id="recipient-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="recipient-phone">Phone Number</Label>
                        <Input
                            id="recipient-phone"
                            type="tel"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="e.g. +255 7XX XXX XXX"
                            required
                        />
                        {errors.phone && <p className="text-sm text-red-600">{errors.phone}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="recipient-email">Email (optional)</Label>
                        <Input
                            id="recipient-email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="recipient-address">Physical Address</Label>
                        <Input
                            id="recipient-address"
                            value={data.address}
                            onChange={(e) => setData('address', e.target.value)}
                        />
                        {errors.address && <p className="text-sm text-red-600">{errors.address}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="recipient-national-id">National ID / Employee ID</Label>
                        <Input
                            id="recipient-national-id"
                            value={data.national_id}
                            onChange={(e) => setData('national_id', e.target.value)}
                        />
                        {errors.national_id && (
                            <p className="text-sm text-red-600">{errors.national_id}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="recipient-status">Status</Label>
                        <select
                            id="recipient-status"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                            value={data.status}
                            onChange={(e) =>
                                setData('status', e.target.value as 'active' | 'inactive')
                            }
                        >
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                        {errors.status && <p className="text-sm text-red-600">{errors.status}</p>}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={mode === 'create' ? 'Register' : 'Save'}
                        processingLabel={mode === 'create' ? 'Saving…' : 'Updating…'}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
