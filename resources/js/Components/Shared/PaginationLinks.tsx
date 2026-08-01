import { Paginated } from '@/types';
import { Link, router } from '@inertiajs/react';

const PER_PAGE_OPTIONS = [10, 25, 50, 100] as const;

interface PaginationLinksProps<T> {
    paginator: Paginated<T> | null | undefined;
    /** Query param for this paginator's page (default: page). Reset when rows-per-page changes. */
    pageName?: string;
}

export default function PaginationLinks<T>({
    paginator,
    pageName = 'page',
}: PaginationLinksProps<T>) {
    if (!paginator) {
        return null;
    }

    const data = paginator.data ?? [];
    const total = paginator.total ?? data.length;
    const links = paginator.links ?? [];
    const lastPage = paginator.last_page ?? 1;
    const currentPage = paginator.current_page ?? 1;
    const perPage = paginator.per_page ?? 25;

    if (total === 0 && data.length === 0) {
        return null;
    }

    function changePerPage(next: number) {
        const url = new URL(window.location.href);
        url.searchParams.set('per_page', String(next));
        url.searchParams.delete(pageName);
        if (pageName !== 'page') {
            url.searchParams.delete('page');
        }

        router.get(url.pathname + url.search, {}, { preserveScroll: true, preserveState: true });
    }

    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-4 py-3">
            <div className="flex flex-wrap items-center gap-3">
                <p className="text-xs text-slate-500">
                    {lastPage > 1
                        ? `Page ${currentPage} of ${lastPage} · ${total} results`
                        : `Showing ${data.length} of ${total} results`}
                </p>
                <label className="flex items-center gap-2 text-xs text-slate-500">
                    <span>Rows</span>
                    <select
                        className="h-8 rounded-md border border-slate-200 bg-white px-2 text-xs text-slate-700"
                        value={
                            PER_PAGE_OPTIONS.includes(perPage as (typeof PER_PAGE_OPTIONS)[number])
                                ? perPage
                                : 25
                        }
                        onChange={(e) => changePerPage(Number(e.target.value))}
                        aria-label="Rows per page"
                    >
                        {PER_PAGE_OPTIONS.map((option) => (
                            <option key={option} value={option}>
                                {option}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            {lastPage > 1 && links.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {links.map((link, index) => (
                        <Link
                            key={`${link.label}-${index}`}
                            href={link.url ?? '#'}
                            preserveScroll
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
            )}
        </div>
    );
}
