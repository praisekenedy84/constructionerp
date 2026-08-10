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

type ExpenseType = 'direct' | 'indirect';

export interface RequisitionCategoryRow {
    id: number;
    name: string;
    description: string | null;
    expense_type: ExpenseType;
    is_active: boolean;
    sort_order: number;
    created_at: string;
}

interface CategoriesIndexProps extends PageProps {
    categories: Paginated<RequisitionCategoryRow>;
    filters: ListingFilters & { expense_type?: string };
}

type FormMode = 'create' | 'edit';

const EXPENSE_TYPE_OPTIONS: { value: ExpenseType; label: string; hint: string }[] = [
    {
        value: 'direct',
        label: 'Project — Direct expense',
        hint: 'Shown when creating project requisitions',
    },
    {
        value: 'indirect',
        label: 'Administrative — Indirect expense',
        hint: 'Shown when creating administrative requisitions',
    },
];

function expenseTypeLabel(type: ExpenseType): string {
    return EXPENSE_TYPE_OPTIONS.find((option) => option.value === type)?.label ?? type;
}

export default function RequisitionCategoriesIndex() {
    const { categories, filters, auth } = usePage<CategoriesIndexProps>().props;
    const rows = categories.data ?? [];
    const canCreate = hasPermission(auth.user, 'requisitions', 'create');
    const canUpdate = hasPermission(auth.user, 'requisitions', 'update');

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<FormMode>('create');
    const [editingId, setEditingId] = useState<number | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        description: '',
        expense_type: 'direct' as ExpenseType,
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
            expense_type: 'direct',
            is_active: true,
            sort_order: 0,
        });
        setOpen(true);
    }

    function openEdit(category: RequisitionCategoryRow) {
        clearErrors();
        setMode('edit');
        setEditingId(category.id);
        setData({
            name: category.name,
            description: category.description ?? '',
            expense_type: category.expense_type ?? 'direct',
            is_active: category.is_active,
            sort_order: category.sort_order,
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
            post('/requisitions/categories', {
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

        put(`/requisitions/categories/${editingId}`, {
            onSuccess: () => {
                reset();
                setOpen(false);
                setEditingId(null);
            },
        });
    }

    function archiveCategory(category: RequisitionCategoryRow) {
        if (
            !confirm(
                `Remove category “${category.name}”? If it is already used on requisitions it will be deactivated instead.`,
            )
        ) {
            return;
        }
        router.delete(`/requisitions/categories/${category.id}`);
    }

    return (
        <AppShell title="Requisition Categories">
            <Head title="Requisition Categories" />
            <div className="space-y-6">
                <PageHeader
                    title="Requisition Categories"
                    description="Define category labels for project (direct) or administrative (indirect) requisitions. The create form only shows categories matching the request scope."
                    actions={
                        canCreate ? (
                            <Button onClick={openCreate}>
                                <Plus className="h-4 w-4" />
                                New Category
                            </Button>
                        ) : undefined
                    }
                />

                <ListToolbar
                    baseUrl="/requisitions/categories"
                    filters={filters}
                    searchPlaceholder="Search name, description…"
                    sortOptions={[
                        { value: 'sort_order', label: 'Sort order' },
                        { value: 'name', label: 'Name' },
                        { value: 'expense_type', label: 'Expense type' },
                        { value: 'is_active', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                    selectFilters={[
                        {
                            key: 'expense_type',
                            label: 'Expense type',
                            emptyLabel: 'All types',
                            options: EXPENSE_TYPE_OPTIONS.map((option) => ({
                                value: option.value,
                                label: option.label,
                            })),
                        },
                    ]}
                />

                <DataPanel title="Defined Categories" noPadding>
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                <th className="px-6 py-3 font-medium">Name</th>
                                <th className="px-6 py-3 font-medium">Type</th>
                                <th className="px-6 py-3 font-medium">Description</th>
                                <th className="px-6 py-3 font-medium">Status</th>
                                <th className="px-6 py-3 text-right font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {rows.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="px-6 py-12 text-center text-slate-500">
                                        No categories yet. Create the options staff should see when
                                        raising a requisition.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((category) => (
                                    <tr key={category.id}>
                                        <td className="px-6 py-4 font-medium text-slate-900">
                                            {category.name}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {expenseTypeLabel(category.expense_type)}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {category.description || '—'}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span
                                                className={
                                                    category.is_active
                                                        ? 'text-green-700'
                                                        : 'text-slate-400'
                                                }
                                            >
                                                {category.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex justify-end gap-1">
                                                {canUpdate && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(category)}
                                                        aria-label={`Edit ${category.name}`}
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                {canUpdate && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => archiveCategory(category)}
                                                        aria-label={`Archive ${category.name}`}
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
                    <PaginationLinks paginator={categories} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? undefined : closeDialog())}
                title={mode === 'create' ? 'New Category' : 'Edit Category'}
                description="The category name appears in the requisition form dropdown for the matching request scope."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="category_name">Category name</Label>
                        <Input
                            id="category_name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Site materials, Petty cash, Casual labour"
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="category_expense_type">Expense type</Label>
                        <select
                            id="category_expense_type"
                            className="flex h-10 w-full rounded-md border border-slate-200 px-3 text-sm"
                            value={data.expense_type}
                            onChange={(e) =>
                                setData('expense_type', e.target.value as ExpenseType)
                            }
                            required
                        >
                            {EXPENSE_TYPE_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <p className="text-xs text-slate-500">
                            {
                                EXPENSE_TYPE_OPTIONS.find(
                                    (option) => option.value === data.expense_type,
                                )?.hint
                            }
                        </p>
                        {errors.expense_type && (
                            <p className="text-sm text-red-600">{errors.expense_type}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="category_description">Description (optional)</Label>
                        <Input
                            id="category_description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Short note for staff"
                        />
                        {errors.description && (
                            <p className="text-sm text-red-600">{errors.description}</p>
                        )}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="category_sort_order">Sort order</Label>
                        <Input
                            id="category_sort_order"
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
                        submitLabel={mode === 'create' ? 'Create Category' : 'Save Changes'}
                        processingLabel={mode === 'create' ? 'Creating…' : 'Saving…'}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
