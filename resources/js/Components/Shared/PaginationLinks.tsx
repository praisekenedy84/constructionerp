import { Paginated } from '@/types';
import { Link } from '@inertiajs/react';

interface PaginationLinksProps<T> {
    paginator: Paginated<T>;
}

export default function PaginationLinks<T>({ paginator }: PaginationLinksProps<T>) {
    const data = paginator?.data ?? [];
    const total = paginator?.total ?? data.length;

    if (!paginator?.links || paginator.links.length <= 3) {
        return (
            <p className="px-2 text-xs text-slate-500">
                Showing {data.length} of {total} results
            </p>
        );
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <p className="text-xs text-slate-500">
                Page {paginator.current_page} of {paginator.last_page} · {paginator.total} results
            </p>
            <div className="flex flex-wrap gap-1">
                {paginator.links.map((link, index) => (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url ?? '#'}
                        preserveState
                        className={`rounded px-3 py-1 text-xs ${
                            link.active
                                ? 'bg-blue-700 text-white'
                                : link.url
                                  ? 'bg-slate-100 text-slate-700 hover:bg-slate-200'
                                  : 'cursor-not-allowed text-slate-300'
                        }`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ))}
            </div>
        </div>
    );
}
