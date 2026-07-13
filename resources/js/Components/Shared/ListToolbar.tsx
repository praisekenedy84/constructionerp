import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { coerceListingFilters } from '@/lib/listing';
import { ListingFilters, SortOption } from '@/types';
import { router } from '@inertiajs/react';
import { ArrowDownAZ, ArrowUpAZ, Search } from 'lucide-react';
import { FormEvent, ReactNode, useMemo, useState } from 'react';

export interface SelectFilter {
    key: string;
    label: string;
    options: { value: string; label: string }[];
    emptyLabel?: string;
}

export interface TextFilter {
    key: string;
    label: string;
    placeholder?: string;
}

interface ListToolbarProps {
    baseUrl: string;
    filters: ListingFilters & Record<string, string | undefined>;
    sortOptions: SortOption[];
    searchPlaceholder?: string;
    selectFilters?: SelectFilter[];
    textFilters?: TextFilter[];
    extraFilters?: ReactNode;
}

const CORE_KEYS = ['search', 'from', 'to', 'sort', 'direction'];

export default function ListToolbar({
    baseUrl,
    filters: rawFilters = {},
    sortOptions,
    searchPlaceholder = 'Search…',
    selectFilters = [],
    textFilters = [],
    extraFilters,
}: ListToolbarProps) {
    const filters = coerceListingFilters(rawFilters);

    const extraKeys = useMemo(
        () => [
            ...selectFilters.map((f) => f.key),
            ...textFilters.map((f) => f.key),
        ],
        [selectFilters, textFilters],
    );

    const [search, setSearch] = useState(filters.search ?? '');
    const [from, setFrom] = useState(filters.from ?? '');
    const [to, setTo] = useState(filters.to ?? '');
    const [sort, setSort] = useState(filters.sort ?? sortOptions[0]?.value ?? 'created_at');
    const [direction, setDirection] = useState<'asc' | 'desc'>(
        filters.direction === 'asc' ? 'asc' : 'desc',
    );
    const [extras, setExtras] = useState<Record<string, string>>(() => {
        const initial: Record<string, string> = {};
        extraKeys.forEach((key) => {
            if (filters[key]) {
                initial[key] = filters[key] as string;
            }
        });
        Object.entries(filters).forEach(([key, value]) => {
            if (!CORE_KEYS.includes(key) && !extraKeys.includes(key) && value) {
                initial[key] = value;
            }
        });
        return initial;
    });

    function setExtra(key: string, value: string) {
        setExtras((current) => ({ ...current, [key]: value }));
    }

    function submit(e?: FormEvent) {
        e?.preventDefault();

        const payload: Record<string, string | undefined> = {
            ...extras,
            search: search || undefined,
            from: from || undefined,
            to: to || undefined,
            sort,
            direction,
        };

        Object.keys(payload).forEach((key) => {
            if (payload[key] === undefined || payload[key] === '') {
                delete payload[key];
            }
        });

        router.get(baseUrl, payload, { preserveState: true, replace: true });
    }

    function clearFilters() {
        const preserved: Record<string, string | undefined> = {};
        Object.entries(filters).forEach(([key, value]) => {
            if (
                !CORE_KEYS.includes(key) &&
                !extraKeys.includes(key) &&
                value
            ) {
                preserved[key] = value;
            }
        });

        setSearch('');
        setFrom('');
        setTo('');
        setSort(sortOptions[0]?.value ?? 'created_at');
        setDirection('desc');
        setExtras({});

        router.get(baseUrl, preserved, { preserveState: true, replace: true });
    }

    return (
        <form onSubmit={submit} className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div className="grid gap-3 lg:grid-cols-[1fr_auto_auto_auto] lg:items-end">
                <div className="space-y-2">
                    <Label htmlFor="list-search">Search</Label>
                    <Input
                        id="list-search"
                        placeholder={searchPlaceholder}
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="list-from">From date</Label>
                    <Input
                        id="list-from"
                        type="date"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                    />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="list-to">To date</Label>
                    <Input
                        id="list-to"
                        type="date"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                    />
                </div>

                <div className="flex gap-2">
                    <Button type="submit">
                        <Search className="mr-2 h-4 w-4" />
                        Search
                    </Button>
                    <Button type="button" variant="outline" onClick={clearFilters}>
                        Clear
                    </Button>
                </div>
            </div>

            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-2">
                    <Label htmlFor="list-sort">Sort by</Label>
                    <div className="flex gap-2">
                        <select
                            id="list-sort"
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                            value={sort}
                            onChange={(e) => setSort(e.target.value)}
                        >
                            {sortOptions.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDirection(direction === 'asc' ? 'desc' : 'asc')}
                            title={direction === 'asc' ? 'Ascending' : 'Descending'}
                        >
                            {direction === 'asc' ? (
                                <ArrowUpAZ className="h-4 w-4" />
                            ) : (
                                <ArrowDownAZ className="h-4 w-4" />
                            )}
                        </Button>
                    </div>
                </div>

                {selectFilters.map((filter) => (
                    <div key={filter.key} className="space-y-2">
                        <Label htmlFor={`filter-${filter.key}`}>{filter.label}</Label>
                        <select
                            id={`filter-${filter.key}`}
                            className="h-10 rounded-md border border-slate-200 px-3 text-sm"
                            value={extras[filter.key] ?? ''}
                            onChange={(e) => setExtra(filter.key, e.target.value)}
                        >
                            <option value="">{filter.emptyLabel ?? `All ${filter.label.toLowerCase()}`}</option>
                            {filter.options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </div>
                ))}

                {textFilters.map((filter) => (
                    <div key={filter.key} className="space-y-2">
                        <Label htmlFor={`filter-${filter.key}`}>{filter.label}</Label>
                        <Input
                            id={`filter-${filter.key}`}
                            placeholder={filter.placeholder}
                            value={extras[filter.key] ?? ''}
                            onChange={(e) => setExtra(filter.key, e.target.value)}
                            className="w-40"
                        />
                    </div>
                ))}

                {extraFilters}
            </div>
        </form>
    );
}
