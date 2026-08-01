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

export interface ComplianceRuleRow {
    id: number;
    name: string;
    description: string | null;
    is_active: boolean;
    created_at: string;
}

interface ComplianceRulesIndexProps extends PageProps {
    rules: Paginated<ComplianceRuleRow>;
    filters: ListingFilters;
}

type FormMode = 'create' | 'edit';

export default function ComplianceRulesIndex() {
    const { rules, filters, auth } = usePage<ComplianceRulesIndexProps>().props;
    const rows = rules.data ?? [];
    const canCreate = hasPermission(auth.user, 'projects', 'create');
    const canUpdate = hasPermission(auth.user, 'projects', 'update');
    const canDelete = hasPermission(auth.user, 'projects', 'delete-soft');

    const [open, setOpen] = useState(false);
    const [mode, setMode] = useState<FormMode>('create');
    const [editingId, setEditingId] = useState<number | null>(null);

    const { data, setData, post, put, processing, errors, reset, clearErrors, isDirty } = useForm({
        name: '',
        description: '',
        is_active: true as boolean,
    });

    function openCreate() {
        clearErrors();
        reset();
        setMode('create');
        setEditingId(null);
        setData({ name: '', description: '', is_active: true });
        setOpen(true);
    }

    function openEdit(rule: ComplianceRuleRow) {
        clearErrors();
        setMode('edit');
        setEditingId(rule.id);
        setData({
            name: rule.name,
            description: rule.description ?? '',
            is_active: rule.is_active,
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
            post('/projects/compliance-rules', {
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

        put(`/projects/compliance-rules/${editingId}`, {
            onSuccess: () => {
                reset();
                setOpen(false);
                setEditingId(null);
            },
        });
    }

    function archiveRule(rule: ComplianceRuleRow) {
        if (!confirm(`Archive compliance rule “${rule.name}”? It will no longer appear in IPC dropdowns.`)) {
            return;
        }
        router.delete(`/projects/compliance-rules/${rule.id}`);
    }

    return (
        <AppShell title="Compliance Rules">
            <Head title="Compliance Rules" />
            <div className="space-y-6">
                <PageHeader
                    title="Compliance Rules"
                    description="Predefine rules once, then select them from the dropdown when creating IPCs."
                    actions={
                        canCreate ? (
                            <Button onClick={openCreate}>
                                <Plus className="h-4 w-4" />
                                New Compliance Rule
                            </Button>
                        ) : undefined
                    }
                />

                <ListToolbar
                    baseUrl="/projects/compliance-rules"
                    filters={filters}
                    searchPlaceholder="Search name, description…"
                    sortOptions={[
                        { value: 'name', label: 'Name' },
                        { value: 'is_active', label: 'Status' },
                        { value: 'created_at', label: 'Date created' },
                    ]}
                />

                <DataPanel title="Defined Rules" noPadding>
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
                                        No compliance rules yet. Create Retention, WHT, or any
                                        project-specific rule.
                                    </td>
                                </tr>
                            ) : (
                                rows.map((rule) => (
                                    <tr key={rule.id}>
                                        <td className="px-6 py-4 font-medium text-slate-900">
                                            {rule.name}
                                        </td>
                                        <td className="px-6 py-4 text-slate-600">
                                            {rule.description || '—'}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span
                                                className={
                                                    rule.is_active
                                                        ? 'text-green-700'
                                                        : 'text-slate-400'
                                                }
                                            >
                                                {rule.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4">
                                            <div className="flex justify-end gap-1">
                                                {canUpdate && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => openEdit(rule)}
                                                        aria-label={`Edit ${rule.name}`}
                                                    >
                                                        <Pencil className="h-4 w-4" />
                                                    </Button>
                                                )}
                                                {canDelete && (
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => archiveRule(rule)}
                                                        aria-label={`Archive ${rule.name}`}
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
                    <PaginationLinks paginator={rules} />
                </DataPanel>
            </div>

            <Dialog
                open={open}
                onOpenChange={(next) => (next ? undefined : closeDialog())}
                title={mode === 'create' ? 'New Compliance Rule' : 'Edit Compliance Rule'}
                description="This name appears in the IPC compliance dropdown."
            >
                <form onSubmit={submit} className="space-y-4">
                    <div className="space-y-2">
                        <Label htmlFor="rule_name">Rule name</Label>
                        <Input
                            id="rule_name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="e.g. Retention, WHT, Material test"
                            required
                        />
                        {errors.name && <p className="text-sm text-red-600">{errors.name}</p>}
                    </div>
                    <div className="space-y-2">
                        <Label htmlFor="rule_description">Description (optional)</Label>
                        <Input
                            id="rule_description"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Short note for staff"
                        />
                        {errors.description && (
                            <p className="text-sm text-red-600">{errors.description}</p>
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
                            Active (available in IPC dropdowns)
                        </label>
                    )}
                    <DialogFormActions
                        onCancel={closeDialog}
                        processing={processing}
                        submitLabel={mode === 'create' ? 'Create Rule' : 'Save Changes'}
                        processingLabel={mode === 'create' ? 'Creating…' : 'Saving…'}
                    />
                </form>
            </Dialog>
        </AppShell>
    );
}
