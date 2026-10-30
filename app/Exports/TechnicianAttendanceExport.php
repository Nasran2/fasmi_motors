<?php

namespace App\Exports;

use App\Support\SpreadsheetSafeText;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TechnicianAttendanceExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    public function __construct(protected Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return SpreadsheetSafeText::row([
            'Date',
            'Day',
            'Technician',
            'In Time',
            'Out Time',
            'Working Hours',
            'Late Minutes',
            'Early Leave Minutes',
            'OT Hours',
            'OT Amount (Rs.)',
            'Deduction Amount (Rs.)',
            'Status',
            'Auto Checkout',
            'Manual Edit',
            'Note',
        ]);
    }

    public function map($row): array
    {
        return [
            $row->date?->format('Y-m-d') ?? '—',
            $row->date?->format('l') ?? '—',
            SpreadsheetSafeText::escape($row->technician?->name ?? '—'),
            $row->in_time ? Carbon::parse($row->in_time)->format('h:i A') : '—',
            $row->out_time ? Carbon::parse($row->out_time)->format('h:i A') : '—',
            number_format((float) $row->working_hours, 2, '.', ''),
            (int) $row->late_minutes > 0 ? (int) $row->late_minutes : '—',
            (int) $row->early_leave_minutes > 0 ? (int) $row->early_leave_minutes : '—',
            number_format((float) $row->ot_hours, 2, '.', ''),
            (float) $row->ot_amount > 0 ? number_format((float) $row->ot_amount, 2, '.', '') : '0.00',
            (float) $row->deduction_amount > 0 ? number_format((float) $row->deduction_amount, 2, '.', '') : '0.00',
            $row->status,
            $row->is_auto_checkout ? 'Yes (Closing Time)' : 'No',
            $row->is_manual_edit ? 'Yes' : 'No',
            SpreadsheetSafeText::escape($row->note ?? ''),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1E293B'],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
            ],
        ];
    }
}

