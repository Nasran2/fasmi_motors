<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DailyClosingExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function __construct(private readonly array $rows) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Account', 'Opening Balance', 'Money In', 'Money Out', 'Expected Closing', 'Actual Closing', 'Difference'];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => ['font' => ['bold' => true]]];
    }
}
