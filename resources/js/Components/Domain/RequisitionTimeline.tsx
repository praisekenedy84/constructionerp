import StatusBadge from '@/Components/Shared/StatusBadge';
import { formatCurrency, formatDate } from '@/lib/formatters';
import { RequisitionStatusHistory } from '@/types';

interface RequisitionTimelineProps {
    history: RequisitionStatusHistory[];
}

export default function RequisitionTimeline({ history }: RequisitionTimelineProps) {
    if (history.length === 0) {
        return (
            <p className="py-4 text-center text-sm text-slate-500">No status history yet.</p>
        );
    }

    const sorted = [...history].sort(
        (a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime(),
    );

    return (
        <div className="space-y-4">
            {sorted.map((entry, index) => (
                <div key={entry.id} className="relative flex gap-4">
                    <div className="flex flex-col items-center">
                        <div className="flex h-8 w-8 items-center justify-center rounded-full bg-blue-100 text-xs font-bold text-blue-700">
                            {sorted.length - index}
                        </div>
                        {index < sorted.length - 1 && (
                            <div className="mt-1 w-px flex-1 bg-slate-200" />
                        )}
                    </div>
                    <div className="flex-1 pb-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge status={entry.from_status} />
                            <span className="text-slate-400">→</span>
                            <StatusBadge status={entry.to_status} />
                            <span className="text-xs text-slate-400">
                                {formatDate(entry.created_at)}
                            </span>
                        </div>
                        {entry.actor && (
                            <p className="mt-1 text-sm text-slate-600">
                                by {entry.actor.name}
                            </p>
                        )}
                        {entry.comment && (
                            <p className="mt-1 text-sm text-slate-700">{entry.comment}</p>
                        )}
                        {entry.amendment_reason && (
                            <p className="mt-1 text-sm text-amber-700">
                                Amendment: {entry.amendment_reason}
                            </p>
                        )}
                        {(entry.original_amount || entry.amended_amount) && (
                            <div className="mt-2 flex flex-wrap gap-4 text-xs text-slate-500">
                                {entry.original_amount && (
                                    <span>Original: {formatCurrency(entry.original_amount)}</span>
                                )}
                                {entry.amended_amount && (
                                    <span>Amended: {formatCurrency(entry.amended_amount)}</span>
                                )}
                                {entry.variance && (
                                    <span>Variance: {formatCurrency(entry.variance)}</span>
                                )}
                            </div>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}
