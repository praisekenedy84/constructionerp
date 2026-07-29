import StatusBadge from '@/Components/Shared/StatusBadge';
import { Button } from '@/Components/ui/button';
import { formatCurrency, formatQuantity } from '@/lib/formatters';
import { BoqItem, BoqSection } from '@/types';
import { Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Pencil, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

interface BoqTreeProps {
    sections: BoqSection[];
    projectId?: number;
    canUpdate?: boolean;
}

export default function BoqTree({ sections, projectId, canUpdate = false }: BoqTreeProps) {
    const [expanded, setExpanded] = useState<Set<number>>(
        () => new Set(sections.map((s) => s.id)),
    );
    const [selected, setSelected] = useState<Set<number>>(new Set());

    const allItemIds = useMemo(
        () => sections.flatMap((section) => section.items.map((item) => item.id)),
        [sections],
    );

    const selectedCount = selected.size;
    const allSelected = allItemIds.length > 0 && selectedCount === allItemIds.length;

    function toggle(id: number) {
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function toggleItem(itemId: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(itemId)) {
                next.delete(itemId);
            } else {
                next.add(itemId);
            }
            return next;
        });
    }

    function toggleSectionItems(section: BoqSection) {
        const ids = section.items.map((item) => item.id);
        const allChecked = ids.length > 0 && ids.every((id) => selected.has(id));

        setSelected((prev) => {
            const next = new Set(prev);
            if (allChecked) {
                ids.forEach((id) => next.delete(id));
            } else {
                ids.forEach((id) => next.add(id));
            }
            return next;
        });
    }

    function toggleAll() {
        setSelected(() => (allSelected ? new Set() : new Set(allItemIds)));
    }

    function deleteItem(item: BoqItem) {
        if (!projectId) {
            return;
        }

        if (!confirm(`Delete BOQ item "${item.description}"?`)) {
            return;
        }

        router.delete(`/projects/${projectId}/boq/items/${item.id}`, {
            preserveScroll: true,
        });
    }

    function deleteSelected() {
        if (!projectId || selectedCount === 0) {
            return;
        }

        if (
            !confirm(
                selectedCount === 1
                    ? 'Delete the selected BOQ item?'
                    : `Delete ${selectedCount} selected BOQ items?`,
            )
        ) {
            return;
        }

        router.post(
            `/projects/${projectId}/boq/items/bulk-delete`,
            { ids: Array.from(selected) },
            {
                preserveScroll: true,
                onSuccess: () => setSelected(new Set()),
            },
        );
    }

    if (sections.length === 0) {
        return (
            <div className="space-y-3 py-8 text-center">
                <p className="text-sm text-slate-500">
                    No BOQ sections yet. Add items manually or import from a file.
                </p>
                {projectId && (
                    <div className="flex flex-wrap items-center justify-center gap-2">
                        <Link href={`/projects/${projectId}/boq/create`}>
                            <Button size="sm">Add Items</Button>
                        </Link>
                        <Link href={`/projects/${projectId}/boq/import`}>
                            <Button size="sm" variant="outline">
                                Import file
                            </Button>
                        </Link>
                    </div>
                )}
            </div>
        );
    }

    return (
        <div>
            {canUpdate && (
                <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
                    <label className="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            type="checkbox"
                            className="rounded border-slate-300"
                            checked={allSelected}
                            onChange={toggleAll}
                        />
                        Select all ({allItemIds.length})
                    </label>
                    {selectedCount > 0 && (
                        <div className="flex items-center gap-2">
                            <span className="text-sm text-slate-600">
                                {selectedCount} selected
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className="border-red-200 text-red-700 hover:bg-red-50"
                                onClick={deleteSelected}
                            >
                                <Trash2 className="h-4 w-4" />
                                Delete selected
                            </Button>
                        </div>
                    )}
                </div>
            )}

            <div className="divide-y divide-slate-200">
                {sections.map((section) => {
                    const isOpen = expanded.has(section.id);
                    const sectionTotal = section.items.reduce(
                        (sum, item) => sum + parseFloat(item.budgeted_amount),
                        0,
                    );
                    const sectionIds = section.items.map((item) => item.id);
                    const sectionAllSelected =
                        sectionIds.length > 0 && sectionIds.every((id) => selected.has(id));
                    const sectionSomeSelected =
                        !sectionAllSelected && sectionIds.some((id) => selected.has(id));

                    return (
                        <div key={section.id}>
                            <div className="flex w-full items-center gap-3 px-4 py-3 hover:bg-slate-50">
                                {canUpdate && (
                                    <input
                                        type="checkbox"
                                        className="rounded border-slate-300"
                                        checked={sectionAllSelected}
                                        ref={(el) => {
                                            if (el) {
                                                el.indeterminate = sectionSomeSelected;
                                            }
                                        }}
                                        onChange={() => toggleSectionItems(section)}
                                        onClick={(e) => e.stopPropagation()}
                                        aria-label={`Select all items in ${section.name}`}
                                    />
                                )}
                                <button
                                    type="button"
                                    onClick={() => toggle(section.id)}
                                    className="flex min-w-0 flex-1 items-center gap-3 text-left"
                                >
                                    {isOpen ? (
                                        <ChevronDown className="h-4 w-4 shrink-0 text-slate-400" />
                                    ) : (
                                        <ChevronRight className="h-4 w-4 shrink-0 text-slate-400" />
                                    )}
                                    <span className="flex-1 truncate font-medium text-slate-900">
                                        {section.name}
                                    </span>
                                    <span className="shrink-0 text-sm text-slate-500">
                                        {section.items.length} items
                                    </span>
                                    <span className="shrink-0 text-sm font-medium text-slate-700">
                                        {formatCurrency(sectionTotal)}
                                    </span>
                                </button>
                            </div>

                            {isOpen && section.items.length > 0 && (
                                <div className="overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-t border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                                {canUpdate && (
                                                    <th className="w-10 px-4 py-2 font-medium" />
                                                )}
                                                <th className="px-4 py-2 font-medium">Description</th>
                                                <th className="px-4 py-2 font-medium">Unit</th>
                                                <th className="px-4 py-2 font-medium">Category</th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Budgeted
                                                </th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Reserved
                                                </th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Consumed
                                                </th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Available
                                                </th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Rate
                                                </th>
                                                <th className="px-4 py-2 text-right font-medium">
                                                    Amount
                                                </th>
                                                {canUpdate && (
                                                    <th className="px-4 py-2 text-right font-medium">
                                                        Actions
                                                    </th>
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {section.items.map((item) => (
                                                <tr
                                                    key={item.id}
                                                    className={
                                                        selected.has(item.id)
                                                            ? 'bg-blue-50/60'
                                                            : 'hover:bg-slate-50'
                                                    }
                                                >
                                                    {canUpdate && (
                                                        <td className="px-4 py-2">
                                                            <input
                                                                type="checkbox"
                                                                className="rounded border-slate-300"
                                                                checked={selected.has(item.id)}
                                                                onChange={() => toggleItem(item.id)}
                                                                aria-label={`Select ${item.description}`}
                                                            />
                                                        </td>
                                                    )}
                                                    <td className="px-4 py-2 text-slate-900">
                                                        {item.description}
                                                    </td>
                                                    <td className="px-4 py-2 text-slate-600">
                                                        {item.unit}
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        <StatusBadge status={item.category} />
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-slate-600">
                                                        {formatQuantity(item.budgeted_qty)}
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-amber-700">
                                                        {formatQuantity(item.reserved_qty)}
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-slate-600">
                                                        {formatQuantity(item.consumed_qty)}
                                                    </td>
                                                    <td className="px-4 py-2 text-right font-medium text-green-700">
                                                        {formatQuantity(item.available_qty)}
                                                    </td>
                                                    <td className="px-4 py-2 text-right text-slate-600">
                                                        {formatCurrency(item.unit_rate)}
                                                    </td>
                                                    <td className="px-4 py-2 text-right font-medium text-slate-900">
                                                        {formatCurrency(item.budgeted_amount)}
                                                    </td>
                                                    {canUpdate && projectId && (
                                                        <td className="px-4 py-2 text-right">
                                                            <div className="flex items-center justify-end gap-1">
                                                                <Link
                                                                    href={`/projects/${projectId}/boq/items/${item.id}/edit`}
                                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-md text-slate-600 hover:bg-slate-100"
                                                                    title="Edit item"
                                                                    aria-label="Edit item"
                                                                >
                                                                    <Pencil className="h-4 w-4" />
                                                                </Link>
                                                                <button
                                                                    type="button"
                                                                    className="inline-flex h-8 w-8 items-center justify-center rounded-md text-red-600 hover:bg-red-50"
                                                                    title="Delete item"
                                                                    aria-label="Delete item"
                                                                    onClick={() => deleteItem(item)}
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </button>
                                                            </div>
                                                        </td>
                                                    )}
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
