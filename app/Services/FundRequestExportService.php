<?php

namespace App\Services;

use App\Enums\CashAllocationStatus;
use App\Exports\FundRequestsExport;
use App\Models\CashAllocation;
use App\Models\SystemSetting;
use App\Support\ListingQuery;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FundRequestExportService
{
    public function baseQuery(?string $status = null): Builder
    {
        return CashAllocation::query()
            ->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->with(['project', 'requester', 'approver']);
    }

    public function list(Request $request): LengthAwarePaginator
    {
        $status = $request->string('status', 'all')->toString();

        return ListingQuery::for($this->baseQuery($status), $request)
            ->search(['reference_no', 'method', 'project.name', 'requester.name', 'approver.name'])
            ->dateRange('requested_at')
            ->sort(['requested_at', 'status', 'requested_amount', 'received_amount', 'created_at'], 'requested_at')
            ->paginate(25);
    }

    /** @return Collection<int, CashAllocation> */
    public function queryAllocations(Request|array $filters = []): Collection
    {
        $status = $filters instanceof Request
            ? $filters->string('status', 'all')->toString()
            : ($filters['status'] ?? 'all');

        $query = $this->baseQuery($status);

        if ($filters instanceof Request) {
            return ListingQuery::for($query, $filters)
                ->search(['reference_no', 'method', 'project.name', 'requester.name', 'approver.name'])
                ->dateRange('requested_at')
                ->sort(['requested_at', 'status', 'requested_amount', 'received_amount', 'created_at'], 'requested_at')
                ->get();
        }

        return $query->orderByDesc('requested_at')->get();
    }

    /** @return array{rows: Collection<int, array<int, string|int|null>>, summary: array<string, int|string>} */
    public function buildReportData(Request|array $filters = []): array
    {
        $allocations = $this->queryAllocations($filters);

        $rows = $allocations->map(fn (CashAllocation $allocation) => [
            $allocation->id,
            $allocation->project?->code ?? '—',
            $allocation->project?->name ?? '—',
            $allocation->requester?->name ?? '—',
            ucfirst($allocation->status->value),
            $this->formatMoney($allocation->requested_amount),
            $this->formatMoney($allocation->received_amount),
            $this->formatMoney($allocation->utilized_amount),
            $this->formatMoney($allocation->balance),
            $allocation->method ?? '—',
            $allocation->reference_no ?? '—',
            $allocation->requested_at?->format('Y-m-d H:i') ?? '—',
            $allocation->decided_at?->format('Y-m-d H:i') ?? '—',
            $allocation->received_at?->format('Y-m-d H:i') ?? '—',
            $allocation->approver?->name ?? '—',
            $allocation->rejection_reason ?? '—',
        ]);

        return [
            'rows' => $rows,
            'summary' => [
                'total' => $allocations->count(),
                'pending' => $allocations->where('status', CashAllocationStatus::Pending)->count(),
                'approved' => $allocations->where('status', CashAllocationStatus::Approved)->count(),
                'received' => $allocations->where('status', CashAllocationStatus::Received)->count(),
                'rejected' => $allocations->where('status', CashAllocationStatus::Rejected)->count(),
                'total_requested' => $this->formatMoney($allocations->sum('requested_amount')),
                'total_received' => $this->formatMoney($allocations->sum('received_amount')),
            ],
            'allocations' => $allocations,
        ];
    }

    public function exportExcel(Request $request): BinaryFileResponse
    {
        $status = $request->string('status', 'all')->toString();
        $data = $this->buildReportData($request);
        $filename = $this->filename('fund-requests', 'xlsx', $status);

        return Excel::download(
            new FundRequestsExport($data['rows']),
            $filename,
        );
    }

    public function exportPdf(Request $request): Response
    {
        $status = $request->string('status', 'all')->toString();
        $data = $this->buildReportData($request);
        $filename = $this->filename('fund-requests', 'pdf', $status);

        return Pdf::loadView('exports.fund-requests', [
            'allocations' => $data['allocations'],
            'summary' => $data['summary'],
            'statusFilter' => $status,
            'generatedAt' => now()->format('d M Y H:i'),
            'companyName' => $this->companyName(),
        ])
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    private function companyName(): string
    {
        $setting = SystemSetting::where('key', 'ui_settings')->first();

        return is_array($setting?->value) ? ($setting->value['app_name'] ?? 'CRF-ERP') : 'CRF-ERP';
    }

    private function filename(string $base, string $extension, ?string $status): string
    {
        $suffix = $status && $status !== 'all' ? "-{$status}" : '';

        return "{$base}{$suffix}-".now()->format('Y-m-d').".{$extension}";
    }

    private function formatMoney(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', ',');
    }
}
