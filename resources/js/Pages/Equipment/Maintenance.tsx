import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Equipment, EquipmentMaintenance, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface MaintenanceProps extends PageProps {
    maintenances: Paginated<EquipmentMaintenance>;
    filters: ListingFilters;
    equipment: Equipment[];
}

export default function Maintenance() {
    const { maintenances, filters, equipment } = usePage<MaintenanceProps>().props;
    const rows = maintenances.data ?? [];
    const { data, setData, post, processing, reset } = useForm({
        equipment_id: '',
        type: 'maintenance' as 'maintenance' | 'repair',
        cost: '',
        description: '',
        date: new Date().toISOString().split('T')[0],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/equipment/maintenance', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Equipment Maintenance">
            <Head title="Equipment Maintenance" />
            <div className="space-y-6">
                <PageHeader
                    title="Maintenance Log"
                    description="Creates EQUIPMENT_COST budget transactions."
                />

                <DataPanel title="Log Maintenance">
                    <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-2">
                            <Label>Equipment</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={data.equipment_id}
                                onChange={(e) => setData('equipment_id', e.target.value)}
                                required
                            >
                                <option value="">Select equipment</option>
                                {equipment.map((eq) => (
                                    <option key={eq.id} value={eq.id}>
                                        {eq.name}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>Type</Label>
                            <select
                                className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                                value={data.type}
                                onChange={(e) =>
                                    setData('type', e.target.value as typeof data.type)
                                }
                            >
                                <option value="maintenance">Maintenance</option>
                                <option value="repair">Repair</option>
                            </select>
                        </div>
                        <div className="space-y-2">
                            <Label>Cost (TZS)</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.cost}
                                onChange={(e) => setData('cost', e.target.value)}
                                required
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>Date</Label>
                            <Input
                                type="date"
                                value={data.date}
                                onChange={(e) => setData('date', e.target.value)}
                            />
                        </div>
                        <div className="space-y-2 sm:col-span-2">
                            <Label>Description</Label>
                            <Input
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                            />
                        </div>
                        <div>
                            <Button type="submit" disabled={processing}>
                                Log Maintenance
                            </Button>
                        </div>
                    </form>
                </DataPanel>

                <ListToolbar
                    baseUrl="/equipment/maintenance"
                    filters={filters}
                    searchPlaceholder="Search description, equipment…"
                    sortOptions={[
                        { value: 'date', label: 'Date' },
                        { value: 'cost', label: 'Cost' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Maintenance History" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Equipment</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
                                        No maintenance records found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((m) => (
                                    <tr key={m.id}>
                                        <td className="px-6 py-4">{formatDate(m.date)}</td>
                                        <td className="px-6 py-4">{m.equipment?.name}</td>
                                        <td className="px-6 py-4 capitalize">{m.type}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(m.cost)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={maintenances} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
