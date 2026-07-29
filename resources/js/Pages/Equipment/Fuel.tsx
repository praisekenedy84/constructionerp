import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import PageHeader from '@/Components/Shared/PageHeader';
import { AmountInput } from '@/Components/ui/amount-input';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { Equipment, EquipmentFuelLog, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface FuelProps extends PageProps {
    fuel_logs: Paginated<EquipmentFuelLog>;
    filters: ListingFilters;
    equipment: Equipment[];
}

export default function Fuel() {
    const { fuel_logs, filters, equipment } = usePage<FuelProps>().props;
    const rows = fuel_logs.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, clearErrors, isDirty } = useForm({
        equipment_id: '',
        liters: '',
        cost: '',
        date: new Date().toISOString().split('T')[0],
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
        post('/equipment/fuel', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Fuel Logs">
            <Head title="Fuel Logs" />
            <div className="space-y-6">
                <PageHeader
                    title="Fuel Logs"
                    description="Creates FUEL_COST budget transactions."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Log Fuel
                        </Button>
                    }
                />

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

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Log Fuel"
                description="Creates FUEL_COST budget transactions."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="fuel-equipment">Equipment</Label>
                        <select
                            id="fuel-equipment"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
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
                        <Label htmlFor="fuel-liters">Liters</Label>
                        <Input
                            id="fuel-liters"
                            type="number"
                            step="0.01"
                            value={data.liters}
                            onChange={(e) => setData('liters', e.target.value)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="fuel-cost">Cost (TZS)</Label>
                        <AmountInput
                            id="fuel-cost"
                            value={data.cost}
                            onValueChange={(v) => setData('cost', v)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="fuel-date">Date</Label>
                        <Input
                            id="fuel-date"
                            type="date"
                            value={data.date}
                            onChange={(e) => setData('date', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Log Fuel"
                        processingLabel="Logging…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
