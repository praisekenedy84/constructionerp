import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Equipment, EquipmentFuelLog, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

interface FuelProps extends PageProps {
    fuel_logs: Paginated<EquipmentFuelLog>;
    filters: ListingFilters;
    equipment: Equipment[];
}

export default function Fuel() {
    const { fuel_logs, filters, equipment } = usePage<FuelProps>().props;
    const rows = fuel_logs.data ?? [];
    const { data, setData, post, processing, reset } = useForm({
        equipment_id: '',
        liters: '',
        cost: '',
        date: new Date().toISOString().split('T')[0],
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/equipment/fuel', { onSuccess: () => reset() });
    }

    return (
        <AppShell title="Fuel Logs">
            <Head title="Fuel Logs" />
            <div className="space-y-6">
                <PageHeader
                    title="Fuel Logs"
                    description="Creates FUEL_COST budget transactions."
                />

                <DataPanel title="Log Fuel">
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
                            <Label>Liters</Label>
                            <Input
                                type="number"
                                step="0.01"
                                value={data.liters}
                                onChange={(e) => setData('liters', e.target.value)}
                                required
                            />
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
                        <div>
                            <Button type="submit" disabled={processing}>
                                Log Fuel
                            </Button>
                        </div>
                    </form>
                </DataPanel>

                <ListToolbar
                    baseUrl="/equipment/fuel"
                    filters={filters}
                    searchPlaceholder="Search equipment…"
                    sortOptions={[
                        { value: 'date', label: 'Date' },
                        { value: 'liters', label: 'Liters' },
                        { value: 'cost', label: 'Cost' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Fuel History" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Date</th>
                                <th className="px-6 py-3 font-medium">Equipment</th>
                                <th className="px-6 py-3 font-medium">Liters</th>
                                <th className="px-6 py-3 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
                                        No fuel logs found.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((log) => (
                                    <tr key={log.id}>
                                        <td className="px-6 py-4">{formatDate(log.date)}</td>
                                        <td className="px-6 py-4">{log.equipment?.name}</td>
                                        <td className="px-6 py-4">{log.liters}</td>
                                        <td className="px-6 py-4 text-right font-medium">
                                            {formatCurrency(log.cost)}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={fuel_logs} />
                </DataPanel>
            </div>
        </AppShell>
    );
}
