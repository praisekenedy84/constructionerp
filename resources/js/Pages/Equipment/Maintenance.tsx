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
import { Equipment, EquipmentMaintenance, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface MaintenanceProps extends PageProps {
    maintenances: Paginated<EquipmentMaintenance>;
    filters: ListingFilters;
    equipment: Equipment[];
}

export default function Maintenance() {
    const { maintenances, filters, equipment } = usePage<MaintenanceProps>().props;
    const rows = maintenances.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, reset, clearErrors, isDirty } = useForm({
        equipment_id: '',
        type: 'maintenance' as 'maintenance' | 'repair',
        cost: '',
        description: '',
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
        post('/equipment/maintenance', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Equipment Maintenance">
            <Head title="Equipment Maintenance" />
            <div className="space-y-6">
                <PageHeader
                    title="Maintenance Log"
                    description="Creates EQUIPMENT_COST budget transactions."
                    actions={
                        <Button onClick={openDialog}>
                            <Plus className="mr-2 h-4 w-4" />
                            Log Maintenance
                        </Button>
                    }
                />

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

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Log Maintenance"
                description="Creates EQUIPMENT_COST budget transactions."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="maint-equipment">Equipment</Label>
                        <select
                            id="maint-equipment"
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
                        <Label htmlFor="maint-type">Type</Label>
                        <select
                            id="maint-type"
                            className="flex h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-800"
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
                        <Label htmlFor="maint-cost">Cost (TZS)</Label>
                        <AmountInput
                            id="maint-cost"
                            value={data.cost}
                            onValueChange={(v) => setData('cost', v)}
                            required
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="maint-date">Date</Label>
                        <Input
                            id="maint-date"
                            type="date"
                            value={data.date}
                            onChange={(e) => setData('date', e.target.value)}
                        />
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="maint-description">Description</Label>
                        <Input
                            id="maint-description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Log Maintenance"
                        processingLabel="Logging…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
