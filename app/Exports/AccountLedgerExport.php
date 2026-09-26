<?php

namespace App\Exports;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountService;
use App\Support\SpreadsheetSafeText;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class AccountLedgerExport implements FromQuery, WithColumnWidths, WithHeadings, WithMapping, WithStyles
{
    private ?float $cashBalance = null;

    public function __construct(
        protected string $accountType,
        protected ?string $fromDate = null,
        protected ?string $toDate = null,
        protected ?string $method = null
    ) {}

    public function query(): Builder
    {
        $account = Account::systemAccount($this->accountType);

        return AccountTransaction::query()
            ->ledger($this->accountType)
            ->posted()
            ->when($this->fromDate, fn (Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
            ->when($this->toDate, fn (Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date))
            ->when($this->method, fn (Builder $q, $method) => $q->where('payment_method', $method))
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');
    }

    public function headings(): array
    {
        return SpreadsheetSafeText::row([
            'Date',
            'Time',
            'Type',
            'Method',
            'Description',
            'Reference',
            'Debit (Dr)',
            'Credit (Cr)',
            'Balance',
        ]);
    }

    public function map($transaction): array
    {
        if ($this->accountType === 'cash') {
            $this->cashBalance ??= app(AccountService::class)->ledgerOpeningBalance('cash', $this->fromDate ?? '1900-01-01');
            $this->cashBalance = round($this->cashBalance + (float) $transaction->credit - (float) $transaction->debit, 2);
            $transaction->running_balance = $this->cashBalance;
        }

        return [
            $transaction->transaction_date->format('Y-m-d'),
            $transaction->transaction_date->format('H:i:s'),
            ucwords(str_replace('_', ' ', $transaction->transaction_type)),
            SpreadsheetSafeText::escape($transaction->payment_method ?? '-'),
            SpreadsheetSafeText::escape($transaction->description),
            $transaction->reference_type ? class_basename($transaction->reference_type).' #'.$transaction->reference_id : '-',
            $transaction->debit > 0 ? number_format($transaction->debit, 2, '.', '') : '-',
            $transaction->credit > 0 ? number_format($transaction->credit, 2, '.', '') : '-',
            number_format($transaction->running_balance, 2, '.', ''),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 12,
            'B' => 10,
            'C' => 12,
            'D' => 15,
            'E' => 35,
            'F' => 20,
            'G' => 15,
            'H' => 15,
            'I' => 15,
        ];
    }
}
