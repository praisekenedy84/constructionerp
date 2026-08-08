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
import { ListingFilters, PageProps, Paginated } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { FormEvent, useState } from 'react';

export interface UnitRow {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    sort_order: number;
    created_at: string;
}

interface UnitsIndexProps extends PageProps {
    units: Paginated<UnitRow>;
    filters: ListingFilters;
}

type FormMode = 'create' | 'edit';

export default function RequisitionUnitsIndex() {
    const { units, filters, auth } = usePage<UnitsIndexProps>().props;
    const rows = units.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<FormMode>('create');
    const [editingId, setEditingId] = useState<number | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        description: '',
        is_active: true as boolean,
        sort_order: 0,
    });

    function openCreate() {
        clearErrors();
        reset();
        setMode('create');
        setEditingId(null);
        setData({
            name: '',
            description: '',
            is_active: true,
            sort_order: 0,
        });
        setOpen(true);
    }

    function openEdit(unit: UnitRow) {
        clearErrors();
        setMode('edit');
        setEditingId(unit.id);
        setData({
            name: unit.name,
            description: unit.description ?? '',
            is_active: unit.is_active,
            sort_order: unit.sort_order,
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
            post('/requisitions/units', {
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

        put(`/requisitions/units/${editingId}`, {
            onSuccess: () => {
                reset();
                setOpen(false);
                setEditingId(null);
            },
        });
    }

    function archiveUnit(unit: UnitRow) {
        if (
            !confirm(
                `Remove unit “${unit.name}”? If it is already used on requisition lines it will be deactivated instead.`,
            )
        ) {
            return;
        }
        router.delete(`/requisitions/units/${unit.id}`);
    }

    return (
        <AppShell title="Requisition Units">
            <Head title="Requisition Units" />
            <div className="space-y-6">
                <PageHeader
                    title="Units"
                    description="Define the unit labels staff pick on requisition lines (bag, L, pcs, day, and so on)."
                    actions={
                        canCreate ? (
                            <Button onClick={openCreate}>
                                <Plus className="h-4 w-4" />
                                New Unit
                            </Button>
                        ) : undefined
                    }
                />

                <ListToolbar
                    baseUrl="/requisitions/units"
                    filters={filters}
                    searchPlaceholder="Search name, description…"
                    sortOptions={[
                        { value: 'sort_order', label: 'Sort order' },
                        { value: 'name', label: 'Name' },
                        { value: 'is_active', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Defined Units" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Description</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={4} className="px-6 py-12 text-center text-slate-500">
                                        No units yet. Create the options staff should see when
                                        raising a requisition.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((unit) => (
                                    <tr key={unit.id}>
                                        <td className="px-6 py-4 font-medium text-slate-900">
                                            {unit.name}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {unit.description || '—'}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span
                                                className={
                                                    unit.is_active
                                                        ? 'text-green-700'
                                                        : 'text-slate-400'
                                                }
                                            >
                                                {unit.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex justify-end gap-1">
                                                {canUpdate && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(unit)}
                                                        aria-label={`Edit ${unit.name}`}
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                {canUpdate && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => archiveUnit(unit)}
                                                        aria-label={`Archive ${unit.name}`}
                                                    >
                                                        <Trash2 className="h-4 w-4 text-slate-500" />
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <PaginationLinks paginator={units} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? undefined : closeDialog())}
                title={mode === 'create' ? 'New Unit' : 'Edit Unit'}
                description="The unit name appears in the requisition form dropdown."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="unit_name">Unit name</Label>
                        <Input
                            id="unit_name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. bag, L, pcs, day"
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="unit_description">Description (optional)</Label>
                        <Input
                            id="unit_description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Short note for staff"
                        />
                        {errors.description && (
                            <p className="text-sm text-red-600">{errors.description}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="unit_sort_order">Sort order</Label>
                        <Input
                            id="unit_sort_order"
                            type="number"
                            min={0}
                            value={String(data.sort_order)}
                            onChange={(e) => setData('sort_order', Number(e.target.value) || 0)}
                        />
                        {errors.sort_order && (
                            <p className="text-sm text-red-600">{errors.sort_order}</p>
                        )}
                    </div>
                    {mode === 'edit' && (
                        <label className="flex items-center gap-2 text-sm text-slate-700">
                            <input
                                type="checkbox"
                                className="rounded border-slate-300"
                                checked={data.is_active}
                                onChange={(e) => setData('is_active', e.target.checked)}
                            />
                            Active (available in requisition dropdown)
                        </label>
                    )}
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={mode === 'create' ? 'Create Unit' : 'Save Changes'}
                        processingLabel={mode === 'create' ? 'Creating…' : 'Saving…'}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
