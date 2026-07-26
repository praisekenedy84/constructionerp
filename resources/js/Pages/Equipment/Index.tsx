import AppShell from '@/Components/Layout/AppShell';
import DataPanel from '@/Components/Shared/DataPanel';
import ListToolbar from '@/Components/Shared/ListToolbar';
import PaginationLinks from '@/Components/Shared/PaginationLinks';
import { LinkButton } from '@/Components/Shared/LinkButton';
import PageHeader from '@/Components/Shared/PageHeader';
import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { Dialog } from '@/Components/ui/dialog';
import { confirmDiscardIfDirty, DialogFormActions } from '@/Components/ui/dialog-form';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Equipment, ListingFilters, PageProps, Paginated } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface EquipmentIndexProps extends PageProps {
    equipment: Paginated<Equipment>;
    filters: ListingFilters;
}

export default function EquipmentIndex() {
    const { equipment, filters } = usePage<EquipmentIndexProps>().props;
    const rows = equipment.data ?? [];
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        type: '',
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
        post('/equipment', {
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    }

    return (
        <AppShell title="Equipment">
            <Head title="Equipment" />
            <div className="space-y-6">
                <PageHeader
                    title="Equipment"
                    description="Fleet registry and utilization."
                    actions={
                        <>
                            <Button onClick={openDialog}>
                                <Plus className="mr-2 h-4 w-4" />
                                Register Equipment
                            </Button>
                            <LinkButton href="/equipment/assignments">Assignments</LinkButton>
                            <LinkButton href="/equipment/maintenance">Maintenance</LinkButton>
                            <LinkButton href="/equipment/fuel">Fuel Logs</LinkButton>
                        </>
                    }
                />

                <ListToolbar
                    baseUrl="/equipment"
                    filters={filters}
                    searchPlaceholder="Search name, type…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'type', label: 'Type' },
                        { value: 'status', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Equipment Fleet" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={3} className="px-6 py-12 text-center text-slate-500">
                                        No equipment registered.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((eq) => (
                                    <tr key={eq.id}>
                                        <td className="px-6 py-4 font-medium">{eq.name}</td>
                                        <td className="px-6 py-4 text-slate-600">{eq.type}</td>
                                        <td className="px-6 py-4">
                                            <StatusBadge status={String(eq.status)} />
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={equipment} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? openDialog() : closeDialog())}
                title="Register Equipment"
                description="Add equipment to the fleet registry."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="equipment-name">Name</Label>
                        <Input
                            id="equipment-name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="equipment-type">Type</Label>
                        <Input
                            id="equipment-type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            required
                        />
                    </div>
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel="Register"
                        processingLabel="Registering…"
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
