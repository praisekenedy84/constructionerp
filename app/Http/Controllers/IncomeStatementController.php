<?php

namespace App\Http\Controllers;

use App\Http\Requests\FinalizeIncomeStatementRequest;
use App\Services\IncomeStatementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class IncomeStatementController extends Controller
{
    public function __construct(
        private IncomeStatementService $incomeStatementService,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $filters = [
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'project_id' => $request->input('project_id'),
            'memo_no' => $request->input('memo_no'),
        ];

        $statement = $this->incomeStatementService->build($filters, [
            'interest' => '0',
            'depreciation' => '0',
            'corporate_tax' => '0',
            'adhoc' => [],
        ]);

        return Inertia::render('Finance/IncomeStatement', [
            'mode' => 'draft',
            'statement' => $statement,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'project_id' => $filters['project_id'] ? (string) $filters['project_id'] : '',
                'memo_no' => $filters['memo_no'] ?? '',
            ],
        ]);
    }

    public function finalize(FinalizeIncomeStatementRequest $request): Response
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $filters = $request->filters();
        $adjustments = $request->adjustments();
        $statement = $this->incomeStatementService->build($filters, $adjustments);

        return Inertia::render('Finance/IncomeStatement', [
            'mode' => 'final',
            'statement' => $statement,
            'filters' => [
                'from' => $filters['from'] ?? '',
                'to' => $filters['to'] ?? '',
                'project_id' => isset($filters['project_id']) ? (string) $filters['project_id'] : '',
                'memo_no' => $filters['memo_no'] ?? '',
            ],
        ]);
    }

    public function export(FinalizeIncomeStatementRequest $request): BinaryFileResponse|StreamedResponse|HttpResponse
    {
        $this->authorizePermission($request->user(), 'budgets', 'read');

        $filters = $request->filters();
        $adjustments = $request->adjustments();
        $statement = $this->incomeStatementService->build($filters, $adjustments);
        $rows = $this->incomeStatementService->exportRows($statement);
        $format = $request->validated('format') ?? 'csv';
        $filename = 'income-statement-'.now()->format('Ymd-His');

        if ($format === 'pdf') {
            return Pdf::loadView('exports.income-statement', [
                'statement' => $statement,
                'rows' => $rows,
            ])
                ->setPaper('a4')
                ->download($filename.'.pdf');
        }

        if ($format === 'xlsx') {
            $export = new class($rows) implements FromArray, WithHeadings, WithStyles
            {
                /** @param  list<array{key: string, label: string, amount: string}>  $rows */
                public function __construct(private array $rows) {}

                public function array(): array
                {
                    return array_map(
                        fn (array $row) => [$row['label'], $row['amount']],
                        $this->rows,
                    );
                }

                public function headings(): array
                {
                    return ['Line', 'Amount'];
                }

                public function styles(Worksheet $sheet): array
                {
                    $lastRow = max(2, count($this->rows) + 1);

                    return [
                        1 => ['font' => ['name' => 'Poppins', 'bold' => true]],
                        "A1:B{$lastRow}" => ['font' => ['name' => 'Poppins']],
                    ];
                }
            };

            return Excel::download($export, $filename.'.xlsx');
        }

        return response()->streamDownload(function () use ($rows, $statement) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Income Statement']);
            if ($statement['memo_no']) {
                fputcsv($out, ['Memo No.', $statement['memo_no']]);
            }
            fputcsv($out, [
                'Period',
                ($statement['period']['from'] ?? '…').' to '.($statement['period']['to'] ?? '…'),
            ]);
            fputcsv($out, []);
            fputcsv($out, ['Line', 'Amount']);

            foreach ($rows as $row) {
                if (str_starts_with($row['key'], 'header_')) {
                    fputcsv($out, [$row['label'], '']);
                } else {
                    fputcsv($out, [$row['label'], $row['amount']]);
                }
            }

            fclose($out);
        }, $filename.'.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
