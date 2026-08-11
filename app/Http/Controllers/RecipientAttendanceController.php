<?php

namespace App\Http\Controllers;

use App\Exports\RecipientAttendanceExport;
use App\Models\Project;
use App\Models\Recipient;
use App\Support\ListingQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RecipientAttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $associations = $this->associationQuery($request);
        $summary = $this->summaryFrom($associations, $request);
        $listing = $this->paginateRecipients($associations, $request);

        return Inertia::render('Recipients/Attendance', [
            'recipients' => $listing,
            'filters' => [
                'search' => $request->input('search'),
                'from' => $request->input('from'),
                'to' => $request->input('to'),
                'project_id' => $request->input('project_id'),
                'recipient_id' => $request->input('recipient_id'),
                'sort' => $request->input('sort', 'last_seen'),
                'direction' => $request->input('direction', 'desc'),
                'per_page' => $request->input('per_page', ListingQuery::PER_PAGE),
            ],
            'summary' => $summary,
            'filterOptions' => [
                'projects' => Project::query()->orderBy('name')->get(['id', 'code', 'name']),
                'recipients' => Recipient::query()->orderBy('name')->get(['id', 'name', 'phone', 'status']),
            ],
        ]);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $this->authorizePermission($request->user(), 'requisitions', 'read');

        $associations = $this->associationQuery($request);
        $recipientSummaries = $this->applyRecipientSort(
            $this->recipientSummaryQuery($associations, $request),
            $request,
        )->get();

        $associationRows = $this->applyAssociationSort(clone $associations)->get();

        $summaryRows = $recipientSummaries->map(fn ($row) => [
            $row->recipient_name,
            $row->recipient_phone,
            ucfirst($row->recipient_status),
            (int) $row->project_count,
            (int) $row->staff_project_count,
            (int) $row->requisition_project_count,
            (int) $row->requisition_count,
            $row->first_seen
                ? \Illuminate\Support\Carbon::parse($row->first_seen)->toDateString()
                : null,
            $row->last_seen
                ? \Illuminate\Support\Carbon::parse($row->last_seen)->toDateString()
                : null,
        ]);

        $detailRows = $associationRows->map(fn ($row) => [
            $row->recipient_name,
            $row->recipient_phone,
            $row->project_code,
            $row->project_name,
            ((int) $row->is_staff) === 1 ? 'Yes' : 'No',
            (int) $row->requisition_count,
            $row->first_seen
                ? \Illuminate\Support\Carbon::parse($row->first_seen)->toDateString()
                : null,
            $row->last_seen
                ? \Illuminate\Support\Carbon::parse($row->last_seen)->toDateString()
                : null,
        ]);

        return Excel::download(
            new RecipientAttendanceExport($summaryRows, $detailRows),
            'recipient-attendance-'.now()->format('Y-m-d-His').'.xlsx',
        );
    }

    /**
     * Distinct recipient ↔ project pairs from requisition inclusions and project staff.
     */
    private function associationQuery(Request $request): Builder
    {
        $requisitionStats = $this->requisitionStatsSubquery($request);

        $pairUnion = DB::query()
            ->fromSub($requisitionStats, 'requisition_stats')
            ->select(['recipient_id', 'project_id'])
            ->union(
                DB::table('project_recipient')->select(['recipient_id', 'project_id']),
            );

        $pairs = DB::query()
            ->fromSub($pairUnion, 'all_pairs')
            ->select(['recipient_id', 'project_id'])
            ->groupBy(['recipient_id', 'project_id']);

        $query = DB::query()
            ->fromSub($pairs, 'pairs')
            ->join('recipients', 'recipients.id', '=', 'pairs.recipient_id')
            ->join('projects', 'projects.id', '=', 'pairs.project_id')
            ->leftJoinSub($requisitionStats, 'req_stats', function ($join) {
                $join->on('req_stats.recipient_id', '=', 'pairs.recipient_id')
                    ->on('req_stats.project_id', '=', 'pairs.project_id');
            })
            ->leftJoin('project_recipient as pr', function ($join) {
                $join->on('pr.recipient_id', '=', 'pairs.recipient_id')
                    ->on('pr.project_id', '=', 'pairs.project_id');
            })
            ->whereNull('recipients.deleted_at')
            ->whereNull('projects.deleted_at')
            ->select([
                'pairs.recipient_id',
                'pairs.project_id',
                'recipients.name as recipient_name',
                'recipients.phone as recipient_phone',
                'recipients.status as recipient_status',
                'projects.code as project_code',
                'projects.name as project_name',
                DB::raw('coalesce(req_stats.requisition_count, 0) as requisition_count'),
                DB::raw('case when pr.recipient_id is not null then 1 else 0 end as is_staff'),
                DB::raw('case
                    when req_stats.first_seen is not null and pr.created_at is not null
                        then least(req_stats.first_seen, pr.created_at)
                    when req_stats.first_seen is not null then req_stats.first_seen
                    else pr.created_at
                end as first_seen'),
                DB::raw('case
                    when req_stats.last_seen is not null and pr.updated_at is not null
                        then greatest(req_stats.last_seen, pr.updated_at)
                    when req_stats.last_seen is not null then req_stats.last_seen
                    else pr.updated_at
                end as last_seen'),
            ]);

        if ($request->filled('project_id')) {
            $query->where('pairs.project_id', $request->integer('project_id'));
        }

        if ($request->filled('recipient_id')) {
            $query->where('pairs.recipient_id', $request->integer('recipient_id'));
        }

        $search = trim($request->string('search')->toString());
        if ($search !== '') {
            $like = "%{$search}%";
            $query->where(function (Builder $builder) use ($like) {
                $builder->where('recipients.name', 'like', $like)
                    ->orWhere('recipients.phone', 'like', $like)
                    ->orWhere('projects.code', 'like', $like)
                    ->orWhere('projects.name', 'like', $like);
            });
        }

        return $query;
    }

    private function requisitionStatsSubquery(Request $request): Builder
    {
        $occurrences = $this->occurrenceSubquery($request);

        return DB::query()
            ->fromSub($occurrences, 'occurrences')
            ->groupBy(['recipient_id', 'project_id'])
            ->select([
                'recipient_id',
                'project_id',
                DB::raw('count(distinct requisition_id) as requisition_count'),
                DB::raw('min(occurred_at) as first_seen'),
                DB::raw('max(occurred_at) as last_seen'),
            ]);
    }

    private function recipientRequisitionTotalsSubquery(Request $request): Builder
    {
        return DB::query()
            ->fromSub($this->occurrenceSubquery($request), 'occurrences')
            ->groupBy('recipient_id')
            ->select([
                'recipient_id',
                DB::raw('count(distinct requisition_id) as requisition_count'),
            ]);
    }

    private function recipientSummaryQuery(Builder $associations, Request $request): Builder
    {
        $requisitionTotals = $this->recipientRequisitionTotalsSubquery($request);

        return DB::query()
            ->fromSub(clone $associations, 'assoc')
            ->leftJoinSub($requisitionTotals, 'req_totals', 'req_totals.recipient_id', '=', 'assoc.recipient_id')
            ->groupBy([
                'assoc.recipient_id',
                'assoc.recipient_name',
                'assoc.recipient_phone',
                'assoc.recipient_status',
            ])
            ->select([
                'assoc.recipient_id',
                'assoc.recipient_name',
                'assoc.recipient_phone',
                'assoc.recipient_status',
                DB::raw('count(distinct assoc.project_id) as project_count'),
                DB::raw('coalesce(max(req_totals.requisition_count), 0) as requisition_count'),
                DB::raw('sum(case when assoc.is_staff = 1 then 1 else 0 end) as staff_project_count'),
                DB::raw('sum(case when assoc.requisition_count > 0 then 1 else 0 end) as requisition_project_count'),
                DB::raw('min(assoc.first_seen) as first_seen'),
                DB::raw('max(assoc.last_seen) as last_seen'),
            ]);
    }

    private function applyRecipientSort(Builder $query, Request $request): Builder
    {
        $sort = $request->string('sort')->toString() ?: 'last_seen';
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';
        $allowed = [
            'last_seen',
            'first_seen',
            'project_count',
            'requisition_count',
            'staff_project_count',
            'recipient_name',
        ];

        if (! in_array($sort, $allowed, true)) {
            $sort = 'last_seen';
            $direction = 'desc';
        }

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('recipient_name');
    }

    private function applyAssociationSort(Builder $query): Builder
    {
        return $query
            ->orderBy('recipient_name')
            ->orderBy('project_code');
    }

    /**
     * Raw recipient inclusions on project requisitions (header, recipients pivot, items).
     */
    private function occurrenceSubquery(Request $request): Builder
    {
        $fromRecipients = DB::table('requisition_recipients as rr')
            ->join('requisitions as r', 'r.id', '=', 'rr.requisition_id')
            ->whereNull('r.deleted_at')
            ->whereNotNull('rr.recipient_id')
            ->whereNotNull('r.project_id')
            ->select([
                'rr.recipient_id',
                'r.project_id',
                'r.id as requisition_id',
                'r.created_at as occurred_at',
            ]);

        $fromHeader = DB::table('requisitions as r')
            ->whereNull('r.deleted_at')
            ->whereNotNull('r.recipient_id')
            ->whereNotNull('r.project_id')
            ->select([
                'r.recipient_id',
                'r.project_id',
                'r.id as requisition_id',
                'r.created_at as occurred_at',
            ]);

        $fromItems = DB::table('requisition_items as ri')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->whereNull('r.deleted_at')
            ->whereNotNull('ri.recipient_id')
            ->whereNotNull('r.project_id')
            ->select([
                'ri.recipient_id',
                'r.project_id',
                'r.id as requisition_id',
                'r.created_at as occurred_at',
            ]);

        if ($from = $request->input('from')) {
            $fromRecipients->whereDate('r.created_at', '>=', $from);
            $fromHeader->whereDate('r.created_at', '>=', $from);
            $fromItems->whereDate('r.created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $fromRecipients->whereDate('r.created_at', '<=', $to);
            $fromHeader->whereDate('r.created_at', '<=', $to);
            $fromItems->whereDate('r.created_at', '<=', $to);
        }

        return $fromRecipients
            ->unionAll($fromHeader)
            ->unionAll($fromItems);
    }

    /**
     * @return array{
     *     recipients: int,
     *     projects: int,
     *     associations: int,
     *     requisitions: int,
     *     staff_assignments: int,
     *     requisition_inclusions: int
     * }
     */
    private function summaryFrom(Builder $associations, Request $request): array
    {
        $totals = DB::query()
            ->fromSub(clone $associations, 'associations')
            ->selectRaw('count(*) as associations')
            ->selectRaw('count(distinct recipient_id) as recipients')
            ->selectRaw('count(distinct project_id) as projects')
            ->selectRaw('coalesce(sum(requisition_count), 0) as requisition_inclusions')
            ->selectRaw('coalesce(sum(is_staff), 0) as staff_assignments')
            ->first();

        $requisitions = (int) DB::query()
            ->fromSub($this->occurrenceSubquery($request), 'occurrences')
            ->selectRaw('count(distinct requisition_id) as total')
            ->value('total');

        return [
            'recipients' => (int) ($totals->recipients ?? 0),
            'projects' => (int) ($totals->projects ?? 0),
            'associations' => (int) ($totals->associations ?? 0),
            'requisitions' => $requisitions,
            'staff_assignments' => (int) ($totals->staff_assignments ?? 0),
            'requisition_inclusions' => (int) ($totals->requisition_inclusions ?? 0),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginateRecipients(Builder $associations, Request $request): LengthAwarePaginator
    {
        $perPage = (int) $request->input('per_page', ListingQuery::PER_PAGE);
        if (! in_array($perPage, ListingQuery::ALLOWED_PER_PAGE, true)) {
            $perPage = ListingQuery::PER_PAGE;
        }

        $page = max(1, (int) $request->input('page', 1));
        $summaryQuery = $this->recipientSummaryQuery($associations, $request);
        $total = DB::query()->fromSub(clone $summaryQuery, 'recipients')->count();

        $recipientRows = $this->applyRecipientSort(clone $summaryQuery, $request)
            ->forPage($page, $perPage)
            ->get();

        $recipientIds = $recipientRows->pluck('recipient_id')->all();
        $projectsByRecipient = $this->projectsForRecipients(clone $associations, $recipientIds);

        $rows = $recipientRows
            ->map(function ($row) use ($projectsByRecipient) {
                $projects = $projectsByRecipient->get((int) $row->recipient_id, collect());

                return [
                    'id' => (int) $row->recipient_id,
                    'recipient_id' => (int) $row->recipient_id,
                    'project_count' => (int) $row->project_count,
                    'requisition_count' => (int) $row->requisition_count,
                    'staff_project_count' => (int) $row->staff_project_count,
                    'requisition_project_count' => (int) $row->requisition_project_count,
                    'first_seen' => $row->first_seen
                        ? \Illuminate\Support\Carbon::parse($row->first_seen)->toDateString()
                        : null,
                    'last_seen' => $row->last_seen
                        ? \Illuminate\Support\Carbon::parse($row->last_seen)->toDateString()
                        : null,
                    'recipient' => [
                        'id' => (int) $row->recipient_id,
                        'name' => $row->recipient_name,
                        'phone' => $row->recipient_phone,
                        'status' => $row->recipient_status,
                    ],
                    'projects' => $projects->map(fn ($project) => [
                        'id' => "{$project->recipient_id}-{$project->project_id}",
                        'project_id' => (int) $project->project_id,
                        'requisition_count' => (int) $project->requisition_count,
                        'is_staff' => (bool) $project->is_staff,
                        'first_seen' => $project->first_seen
                            ? \Illuminate\Support\Carbon::parse($project->first_seen)->toDateString()
                            : null,
                        'last_seen' => $project->last_seen
                            ? \Illuminate\Support\Carbon::parse($project->last_seen)->toDateString()
                            : null,
                        'project' => [
                            'id' => (int) $project->project_id,
                            'code' => $project->project_code,
                            'name' => $project->project_name,
                        ],
                    ])->values()->all(),
                ];
            })
            ->all();

        return new LengthAwarePaginator(
            $rows,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );
    }

    /**
     * @param  list<int>  $recipientIds
     * @return Collection<int, Collection<int, object>>
     */
    private function projectsForRecipients(Builder $associations, array $recipientIds): Collection
    {
        if ($recipientIds === []) {
            return collect();
        }

        return $this->applyAssociationSort($associations)
            ->whereIn('pairs.recipient_id', $recipientIds)
            ->get()
            ->groupBy('recipient_id');
    }
}
