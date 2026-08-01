<?php

namespace App\Services;

use App\Enums\RequisitionStatus;
use App\Exports\RequisitionRegisterExport;
use App\Models\Project;
use App\Models\Requisition;
use App\Models\RequisitionCategory;
use App\Models\RequisitionItem;
use App\Models\User;
use App\Support\ListingQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RequisitionRegisterService
{
    /** @return list<string> */
    private function paidStatuses(): array
    {
        return [
            RequisitionStatus::Fulfilled->value,
            RequisitionStatus::Closed->value,
        ];
    }

    /** @return list<string> */
    private function excludedFromRequested(): array
    {
        return [
            RequisitionStatus::Cancelled->value,
        ];
    }

    public function baseQuery(User $user): Builder
    {
        return RequisitionItem::query()
            ->select('requisition_items.*')
            ->selectRaw(
                '(select count(*) from requisition_items as ri_sn
                  where ri_sn.requisition_id = requisition_items.requisition_id
                    and ri_sn.id <= requisition_items.id) as line_sn'
            )
            ->whereHas('requisition', fn (Builder $q) => $q->visibleTo($user))
            ->with([
                'requisition.project',
                'requisition.requestor',
                'requisition.category',
            ]);
    }

    public function applyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('status')) {
            $query->whereHas('requisition', fn (Builder $q) => $q->where('status', $request->string('status')));
        }

        if ($request->filled('project_id')) {
            $query->whereHas('requisition', fn (Builder $q) => $q->where('project_id', $request->integer('project_id')));
        }

        if ($request->filled('category_id')) {
            $query->whereHas(
                'requisition',
                fn (Builder $q) => $q->where('requisition_category_id', $request->integer('category_id')),
            );
        }

        if ($request->filled('department')) {
            $query->whereHas(
                'requisition',
                fn (Builder $q) => $q->where('department', 'like', '%'.$request->string('department').'%'),
            );
        }

        if ($request->filled('requestor_id')) {
            $query->whereHas(
                'requisition',
                fn (Builder $q) => $q->where('requestor_id', $request->integer('requestor_id')),
            );
        }

        if ($from = $request->input('from')) {
            $query->whereHas('requisition', fn (Builder $q) => $q->whereDate('created_at', '>=', $from));
        }

        if ($to = $request->input('to')) {
            $query->whereHas('requisition', fn (Builder $q) => $q->whereDate('created_at', '<=', $to));
        }

        $search = trim($request->string('search')->toString());
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('requisition_items.description', 'like', "%{$search}%")
                    ->orWhereHas('requisition', function (Builder $q) use ($search) {
                        $q->where('requisition_no', 'like', "%{$search}%")
                            ->orWhere('department', 'like', "%{$search}%")
                            ->orWhereHas(
                                'project',
                                fn (Builder $p) => $p->where('name', 'like', "%{$search}%")
                                    ->orWhere('code', 'like', "%{$search}%"),
                            )
                            ->orWhereHas('requestor', fn (Builder $u) => $u->where('name', 'like', "%{$search}%"))
                            ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$search}%"));
                    });
            });
        }

        return $query;
    }

    public function applySort(Builder $query, Request $request): Builder
    {
        $sort = $request->string('sort')->toString() ?: 'date';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        return match ($sort) {
            'requisition_no' => $query
                ->orderBy(
                    Requisition::select('requisition_no')
                        ->whereColumn('requisitions.id', 'requisition_items.requisition_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy('requisition_items.id'),
            'amount' => $query->orderBy('requisition_items.line_total', $direction)->orderBy('requisition_items.id'),
            'description' => $query->orderBy('requisition_items.description', $direction)->orderBy('requisition_items.id'),
            default => $query
                ->orderBy(
                    Requisition::select('created_at')
                        ->whereColumn('requisitions.id', 'requisition_items.requisition_id')
                        ->limit(1),
                    $direction,
                )
                ->orderBy('requisition_items.id'),
        };
    }

    public function paginate(User $user, Request $request, ?int $perPage = null): LengthAwarePaginator
    {
        $query = $this->applySort(
            $this->applyFilters($this->baseQuery($user), $request),
            $request,
        );

        $resolved = ListingQuery::resolvePerPage($request, $perPage ?? ListingQuery::PER_PAGE);

        /** @var LengthAwarePaginator<int, RequisitionItem> $page */
        $page = $query->paginate($resolved)->withQueryString();
        $page->setCollection(
            $page->getCollection()->map(fn (RequisitionItem $item) => $this->mapRow($item)),
        );

        return $page;
    }

    /**
     * @return array{
     *     total_requested: string,
     *     total_paid: string,
     *     total_pending: string,
     *     requested_pct: float,
     *     paid_pct: float,
     *     pending_pct: float,
     *     line_count: int
     * }
     */
    public function summary(User $user, Request $request): array
    {
        // Build a clean aggregate query — do not reuse baseQuery selects (Postgres GROUP BY).
        $query = $this->applyFilters(
            RequisitionItem::query()->whereHas('requisition', fn (Builder $q) => $q->visibleTo($user)),
            $request,
        )->join('requisitions as req_sum', 'req_sum.id', '=', 'requisition_items.requisition_id');

        $aggregates = $query
            ->toBase()
            ->reorder()
            ->selectRaw(
                'count(requisition_items.id) as line_count,
                 coalesce(sum(case when req_sum.status not in ('.$this->sqlList($this->excludedFromRequested()).') then requisition_items.line_total else 0 end), 0) as total_requested,
                 coalesce(sum(case when req_sum.status in ('.$this->sqlList($this->paidStatuses()).') then requisition_items.line_total else 0 end), 0) as total_paid'
            )
            ->first();

        $requested = (string) ($aggregates->total_requested ?? '0');
        $paid = (string) ($aggregates->total_paid ?? '0');
        $pending = bcsub($requested, $paid, 2);
        if (bccomp($pending, '0', 2) < 0) {
            $pending = '0.00';
        }

        $requestedFloat = (float) $requested;

        return [
            'total_requested' => bcadd($requested, '0', 2),
            'total_paid' => bcadd($paid, '0', 2),
            'total_pending' => bcadd($pending, '0', 2),
            'requested_pct' => $requestedFloat > 0 ? 1.0 : 0.0,
            'paid_pct' => $requestedFloat > 0 ? round(((float) $paid) / $requestedFloat, 6) : 0.0,
            'pending_pct' => $requestedFloat > 0 ? round(((float) $pending) / $requestedFloat, 6) : 0.0,
            'line_count' => (int) ($aggregates->line_count ?? 0),
        ];
    }

    /** @return Collection<int, array<int, string|int|float|null>> */
    public function exportRows(User $user, Request $request): Collection
    {
        $items = $this->applySort(
            $this->applyFilters($this->baseQuery($user), $request),
            $request,
        )->get();

        return $items->map(function (RequisitionItem $item) {
            $row = $this->mapRow($item);

            return [
                $row['date'],
                $row['requested_by'],
                $row['requisition_no'],
                $row['sn'],
                $row['department'],
                $row['description'],
                $row['category'],
                $row['project_code'],
                $row['project_name'],
                $row['unit'],
                $row['quantity'],
                $row['rate'],
                $row['amount'],
                $row['status'],
                $row['paid'],
                $row['pending'],
            ];
        });
    }

    public function exportExcel(User $user, Request $request): BinaryFileResponse
    {
        $rows = $this->exportRows($user, $request);
        $filename = 'requisition-register-'.now()->format('Y-m-d-His').'.xlsx';

        return Excel::download(new RequisitionRegisterExport($rows), $filename);
    }

    /** @return array<string, mixed> */
    public function filterOptions(): array
    {
        return [
            'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
            'categories' => RequisitionCategory::query()->ordered()->get(['id', 'name', 'is_active']),
            'requestors' => User::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /** @return array<string, mixed> */
    public function mapRow(RequisitionItem $item): array
    {
        $requisition = $item->requisition;
        $status = $requisition?->status instanceof RequisitionStatus
            ? $requisition->status->value
            : (string) ($requisition?->status ?? '');
        $amount = bcadd((string) $item->line_total, '0', 2);
        $isPaid = in_array($status, $this->paidStatuses(), true);
        $isExcluded = in_array($status, $this->excludedFromRequested(), true);
        $paid = $isPaid ? $amount : '0.00';
        $pending = (! $isPaid && ! $isExcluded) ? $amount : '0.00';

        return [
            'id' => $item->id,
            'requisition_id' => $item->requisition_id,
            'date' => optional($requisition?->created_at)->toDateString(),
            'requested_by' => $requisition?->requestor?->name ?? '—',
            'requisition_no' => $requisition?->requisition_no ?? '—',
            'sn' => (int) ($item->line_sn ?? 0),
            'department' => $requisition?->department ?? '—',
            'description' => $item->description,
            'category' => $requisition?->category?->name
                ?? (string) ($requisition?->resource_type?->value ?? $requisition?->resource_type ?? '—'),
            'project_code' => $requisition?->project?->code ?? 'ORG',
            'project_name' => $requisition?->project?->name ?? 'Organization',
            'unit' => $item->unit ?? '—',
            'quantity' => (string) $item->quantity,
            'rate' => (string) $item->unit_cost,
            'amount' => $amount,
            'status' => $status,
            'paid' => $paid,
            'pending' => $pending,
            'requestor_id' => $requisition?->requestor_id,
        ];
    }

    /** @param  list<string>  $values */
    private function sqlList(array $values): string
    {
        return collect($values)
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(',');
    }
}
