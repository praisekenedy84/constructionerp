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
import { ListingFilters, PageProps, Paginated, Supplier } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface SuppliersProps extends PageProps {
    suppliers: Paginated<Supplier>;
    filters: ListingFilters;
}

export default function Suppliers() {
    const { suppliers, filters } = usePage<SuppliersProps>().props;
    const rows = suppliers.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        phone: '',
        email: '',
        contact_info: '',
    });

    function openDialog() {
        clearErrors();
        setOpen(true);
    }

    function closeDialog() {
        if (!confirmDiscardIfDirty(isDirty)) {
            return;
        }
        setOpen(false);
        reset();
        clearErrors();
    }

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/procurement/suppliers', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Suppliers">
            <Head title="Suppliers" />
            <div className="space-y-6">
                <PageHeader
                    title="Suppliers"
                    description="Supplier directory."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Add Supplier
                        </Button>
                    }
                />

                <ListToolbar
                    baseUrl="/procurement/suppliers"
                    filters={filters}
                    searchPlaceholder="Search name, phone, email…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'phone', label: 'Phone' },
                        { value: 'email', label: 'Email' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Supplier List" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Phone</th>
                                <th className="px-6 py-3 font-medium">Email</th>
                                <th className="px-6 py-3 font-medium">Notes</th>
                                <th className="px-6 py-3 font-medium">Rating</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No suppliers found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-6 py-4 font-medium">{s.name}</td>
                                        <td className="px-6 py-4 text-slate-600">{s.phone ?? '—'}</td>
                                        <td className="px-6 py-4 text-slate-600">{s.email ?? '—'}</td>
                                        <td className="px-6 py-4 text-slate-600">{s.contact_info ?? '—'}</td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {s.performance_rating ?? '—'}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={suppliers} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Add Supplier"
                description="Add a supplier to the directory."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="supplier-name">Name</Label>
                        <Input
                            id="supplier-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="supplier-phone">Phone</Label>
                        <Input
                            id="supplier-phone"
                            type="tel"
                            value={data.phone}
                            onChange={(e) => setData('phone', e.target.value)}
                            placeholder="e.g. +255 7XX XXX XXX"
                            required
                        />
                        {errors.phone && <p className="text-sm text-red-600">{errors.phone}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="supplier-email">Email</Label>
                        <Input
                            id="supplier-email"
                            type="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            placeholder="supplier@example.com"
                            required
                        />
                        {errors.email && <p className="text-sm text-red-600">{errors.email}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="supplier-contact">Additional notes (optional)</Label>
                        <Input
                            id="supplier-contact"
                            value={data.contact_info}
                            onChange={(e) => setData('contact_info', e.target.value)}
                        />
                        {errors.contact_info && (
                            <p className="text-sm text-red-600">{errors.contact_info}</p>
                        )}
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Add Supplier"
                        processingLabel="Adding…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
