<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ListingQuery
{
    public const PER_PAGE = 25;

    /** @var list<int> */
    public const ALLOWED_PER_PAGE = [10, 25, 50, 100];

    public function __construct(
        private Builder $query,
        private readonly Request $request,
    ) {}

    public static function for(Builder|Relation $query, Request $request): self
    {
        if ($query instanceof Relation) {
            $query = $query->getQuery();
        }

        return new self($query, $request);
    }

    /** @param  list<string>  $columns */
    public function search(array $columns): self
    {
        $search = trim($this->request->string('search')->toString());

        if ($search === '') {
            return $this;
        }

        $this->query->where(function (Builder $builder) use ($columns, $search) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);
                    $builder->orWhereHas($relation, function (Builder $relationQuery) use ($field, $search) {
                        $relationQuery->where($field, 'like', "%{$search}%");
                    });
                } else {
                    $builder->orWhere($column, 'like', "%{$search}%");
                }
            }
        });

        return $this;
    }

    public function dateRange(string $column = 'created_at'): self
    {
        if ($from = $this->request->input('from')) {
            $this->query->whereDate($column, '>=', $from);
        }

        if ($to = $this->request->input('to')) {
            $this->query->whereDate($column, '<=', $to);
        }

        return $this;
    }

    /**
     * @param  list<string>  $allowed
     */
    public function sort(array $allowed, string $default = 'created_at', string $defaultDirection = 'desc'): self
    {
        $sort = $this->request->string('sort')->toString() ?: $default;
        $direction = $this->request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        if (! in_array($sort, $allowed, true)) {
            $sort = $default;
            $direction = $defaultDirection;
        }

        $this->query->orderBy($sort, $direction);

        return $this;
    }

    /**
     * Resolve a safe per-page value from the request (falls back to $default).
     */
    public static function resolvePerPage(Request $request, int $default = self::PER_PAGE): int
    {
        if (! $request->filled('per_page')) {
            return $default;
        }

        $requested = (int) $request->input('per_page');

        return in_array($requested, self::ALLOWED_PER_PAGE, true)
            ? $requested
            : $default;
    }

    public function paginate(?int $perPage = null, string $pageName = 'page'): LengthAwarePaginator
    {
        $resolved = self::resolvePerPage($this->request, $perPage ?? self::PER_PAGE);

        return $this->query->paginate($resolved, ['*'], $pageName)->withQueryString();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, \Illuminate\Database\Eloquent\Model> */
    public function get()
    {
        return $this->query->get();
    }

    /** @param  array<string, mixed>  $extra */
    public function filters(array $extra = []): array|\stdClass
    {
        return self::requestFilters($this->request, $extra);
    }

    /** @param  array<string, mixed>  $extra */
    public static function requestFilters(Request $request, array $extra = []): array|\stdClass
    {
        $filters = array_filter(
            array_merge(
                $request->only(['search', 'from', 'to', 'sort', 'direction', 'per_page']),
                $extra,
            ),
            fn ($value) => $value !== null && $value !== '',
        );

        return $filters === [] ? new \stdClass() : $filters;
    }
}
