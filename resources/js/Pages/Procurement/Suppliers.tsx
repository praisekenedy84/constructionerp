import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { ListingFilters, PageProps, Paginated, Supplier } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface SuppliersProps extends PageProps {
    suppliers: Paginated<Supplier>;
    filters: ListingFilters;
}

export default function Suppliers() {
    const { suppliers, filters } = usePage<SuppliersProps>().props;
    const rows = suppliers.data ?? [];
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        contact_info: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/procurement/suppliers', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Suppliers">
            <Head title="Suppliers" />
            <div className="space-y-6">
                <PageHeader title="Suppliers" description="Supplier directory." />

                <DataPanel title="Add Supplier">
                    <form onSubmit={submit} className="flex flex-wrap items-end gap-4">
                        <div className="space-y-2">
                            <Label>Name</Label>
                            <Input
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                required
                            />
                            {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                        </div>
                        <div className="flex-1 space-y-2">
                            <Label>Contact Info</Label>
                            <Input
                                value={data.contact_info}
                                onChange={(e) => setData('contact_info', e.target.value)}
                            />
                        </div>
                        <Button type="submit" disabled={processing}>
                            Add Supplier
                        </Button>
                    </form>
                </DataPanel>

                <ListToolbar
                    baseUrl="/procurement/suppliers"
                    filters={filters}
                    searchPlaceholder="Search name, contact…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'contact_person', label: 'Contact' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Supplier List" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Contact</th>
                                <th className="px-6 py-3 font-medium">Rating</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={3} className="px-6 py-12 text-center text-slate-500">
                                        No suppliers found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((s) => (
                                    <tr key={s.id}>
                                        <td className="px-6 py-4 font-medium">{s.name}</td>
                                        <td className="px-6 py-4 text-slate-600">{s.contact_info}</td>
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
        </AppShell>
    );
}
