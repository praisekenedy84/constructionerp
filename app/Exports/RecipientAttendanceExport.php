<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RecipientAttendanceExport implements WithMultipleSheets
{
    /** @param  Collection<int, array<int, string|int|float|null>>  $summaryRows */
    /** @param  Collection<int, array<int, string|int|float|null>>  $detailRows */
    public function __construct(
        private Collection $summaryRows,
        private Collection $detailRows,
    ) {}

    /** @return list<RecipientAttendanceSheetExport> */
    public function sheets(): array
    {
        return [
            new RecipientAttendanceSheetExport(
                $this->summaryRows,
                'Recipient Summary',
                [
                    'Recipient Name',
                    'Recipient Phone',
                    'Status',
                    'Projects',
                    'Staff Projects',
                    'Requisition Projects',
                    'Requisitions',
                    'First Activity',
                    'Last Activity',
                ],
            ),
            new RecipientAttendanceSheetExport(
                $this->detailRows,
                'Project Breakdown',
                [
                    'Recipient Name',
                    'Recipient Phone',
                    'Project Code',
                    'Project Name',
                    'Project Staff',
                    'Requisitions',
                    'First Activity',
                    'Last Activity',
                ],
            ),
        ];
    }
}

class RecipientAttendanceSheetExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param  Collection<int, array<int, string|int|float|null>>  $rows */
    /** @param  list<string>  $headings */
    public function __construct(
        private Collection $rows,
        private string $title,
        private array $headings,
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = chr(ord('A') + count($this->headings) - 1);
        $lastRow = max(2, $this->rows->count() + 1);

        return [
            1 => [
                'font' => ['name' => 'Poppins', 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            "A1:{$lastColumn}{$lastRow}" => [
                'font' => ['name' => 'Poppins'],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CBD5E1'],
                    ],
                ],
            ],
        ];
    }
}
