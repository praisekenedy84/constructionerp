<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BoqExport implements FromCollection, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /** @param  Collection<int, array<int, string|int|float|null>>  $rows */
    public function __construct(
        private Collection $rows,
        private string $sheetTitle = 'BOQ',
    ) {}

    public function collection(): Collection
    {
        return $this->rows;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            'Section',
            'Description',
            'Unit',
            'Category',
            'Qty',
            'Rate',
        ];
    }

    public function title(): string
    {
        return $this->sheetTitle;
    }

    public function styles(Worksheet $sheet): array
    {
        $lastColumn = 'F';
        $lastRow = max(2, $this->rows->count() + 1);

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E3A8A'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
            "A1:{$lastColumn}{$lastRow}" => [
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
