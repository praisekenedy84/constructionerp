import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency } from '@/lib/formatters';
import { BoqSection } from '@/types';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';

interface BoqTreeProps {
    sections: BoqSection[];
}

export default function BoqTree({ sections }: BoqTreeProps) {
    const [expanded, setExpanded] = useState<Set<number>>(
        () => new Set(sections.map((s) => s.id)),
    );

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

    if (sections.length === 0) {
        return (
            <p className="py-8 text-center text-sm text-slate-500">
                No BOQ sections found. Import a BOQ to get started.
            </p>
        );
    }

    return (
        <div className="divide-y divide-slate-200">
            {sections.map((section) => {
                const isOpen = expanded.has(section.id);
                const sectionTotal = section.items.reduce(
                    (sum, item) => sum + parseFloat(item.budgeted_amount),
                    0,
                );

                return (
                    <div key={section.id}>
                        <button
                            type="button"
                            onClick={() => toggle(section.id)}
                            className="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-slate-50"
                        >
                            {isOpen ? (
                                <ChevronDown className="h-4 w-4 text-slate-400" />
                            ) : (
                                <ChevronRight className="h-4 w-4 text-slate-400" />
                            )}
                            <span className="flex-1 font-medium text-slate-900">{section.name}</span>
                            <span className="text-sm text-slate-500">
                                {section.items.length} items
                            </span>
                            <span className="text-sm font-medium text-slate-700">
                                {formatCurrency(sectionTotal)}
                            </span>
                        </button>

                        {isOpen && section.items.length > 0 && (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-t border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                                            <th className="px-4 py-2 font-medium">Description</th>
                                            <th className="px-4 py-2 font-medium">Unit</th>
                                            <th className="px-4 py-2 font-medium">Category</th>
                                            <th className="px-4 py-2 text-right font-medium">Budgeted</th>
                                            <th className="px-4 py-2 text-right font-medium">Reserved</th>
                                            <th className="px-4 py-2 text-right font-medium">Consumed</th>
                                            <th className="px-4 py-2 text-right font-medium">Available</th>
                                            <th className="px-4 py-2 text-right font-medium">Rate</th>
                                            <th className="px-4 py-2 text-right font-medium">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {section.items.map((item) => (
                                            <tr key={item.id} className="hover:bg-slate-50">
                                                <td className="px-4 py-2 text-slate-900">
                                                    {item.description}
                                                </td>
                                                <td className="px-4 py-2 text-slate-600">{item.unit}</td>
                                                <td className="px-4 py-2">
                                                    <StatusBadge status={item.category} />
                                                </td>
                                                <td className="px-4 py-2 text-right text-slate-600">
                                                    {item.budgeted_qty}
                                                </td>
                                                <td className="px-4 py-2 text-right text-amber-700">
                                                    {item.reserved_qty}
                                                </td>
                                                <td className="px-4 py-2 text-right text-slate-600">
                                                    {item.consumed_qty}
                                                </td>
                                                <td className="px-4 py-2 text-right font-medium text-green-700">
                                                    {item.available_qty}
                                                </td>
                                                <td className="px-4 py-2 text-right text-slate-600">
                                                    {formatCurrency(item.unit_rate)}
                                                </td>
                                                <td className="px-4 py-2 text-right font-medium text-slate-900">
                                                    {formatCurrency(item.budgeted_amount)}
                                                </td>
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
    );
}
