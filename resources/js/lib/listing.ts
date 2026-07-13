import { ListingFilters } from '@/types';

export function coerceListingFilters(
    filters: ListingFilters | unknown,
): ListingFilters & Record<string, string | undefined> {
    if (!filters || typeof filters !== 'object' || Array.isArray(filters)) {
        return {};
    }

    return filters as ListingFilters & Record<string, string | undefined>;
}
