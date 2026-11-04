<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashierCardBreakdownService
{
    public function __construct(private CashPositionService $cash, private AccountTransactionSourceService $sources) {}

    private function sourceDetails(Collection $entries, User $user): array
    {
        return $entries->map(fn ($entry) => $this->sources->details($entry, $user))
            ->unique(fn ($source) => implode('|', [$source['url'], $source['invoice'], $source['job'], $source['bike']]))->values()->all();
    }

    public function breakdown(string $date, array $summary, ?CashierSession $session, User $user): array
    {
        $collections = $summary['collections']->where('channel', 'cash')->values();
        $sourceIds = $summary['collections']->pluck('source_transaction_ids')->flatten()->unique();
        $entries = AccountTransaction::posted()->whereIn('id', $sourceIds)->get()->keyBy('id');
        $receiptKeys = [];
        $received = $collections->map(function ($receipt, $index) use ($entries, $user, &$receiptKeys) {
            $sourceEntries = collect($receipt['source_transaction_ids'])->map(fn ($id) => $entries->get($id))->filter();
            foreach ($sourceEntries as $entry) {
                $receiptKeys[$entry->id] = 'receipt-'.$index;
            }

            return ['date' => $receipt['date'], 'number' => $receipt['number'], 'description' => $receipt['description'],
                'note' => $sourceEntries->pluck('note')->filter()->unique()->implode("\n"), 'account' => 'Cash receipt',
                'amount' => $receipt['amount'], 'sources' => $this->sourceDetails($sourceEntries, $user)];
        });
        $washing = $summary['collections']->filter(fn ($receipt) => ($receipt['components']['washing'] ?? 0) != 0)
            ->map(function ($receipt) use ($entries, $user) {
                $sourceEntries = collect($receipt['source_transaction_ids'])->map(fn ($id) => $entries->get($id))->filter();

                return ['date' => $receipt['date'], 'number' => $receipt['number'], 'description' => $receipt['description'],
                    'note' => $sourceEntries->pluck('note')->filter()->unique()->implode("\n"),
                    'account' => $receipt['method'].' · washing portion', 'amount' => $receipt['components']['washing'],
                    'sources' => $this->sourceDetails($sourceEntries, $user)];
            })->sortBy('date')->values();

        $groups = $this->cash->movementDetails($date, $session, $summary['collections']);
        $movementRow = function ($group) use ($user) {
            $entries = $group['entries'];
            $entry = $entries->first(fn ($row) => $group['net'] < 0 ? $row->debit > 0 : $row->credit > 0) ?? $entries->first();
            $date = $entry->transaction_date;
            if ($date && $date->format('H:i:s') === '00:00:00' && $entry->created_at) {
                $date = $entry->transaction_date->copy()->setTimeFrom($entry->created_at);
            }

            return ['date' => $date, 'number' => $entry->transaction_no, 'description' => $entry->description,
                'note' => $entries->pluck('note')->filter()->unique()->implode("\n"), 'account' => $group['account'],
                'amount' => $group['net'], 'sources' => $this->sourceDetails($entries, $user)];
        };
        $paid = $groups->where('kind', 'paid')->map($movementRow)->values();
        // Merge receipt allocations across Main, Washing and Spare Parts into one
        // drawer movement, including every bill covered by a grouped receipt.
        $ledgerReceipts = $groups->where('kind', 'receipt')->groupBy(function ($group) use ($receiptKeys) {
            $entry = $group['entries']->first();

            return $receiptKeys[$entry->id] ?? $entry->reference_type.'|'.($entry->reference_id ?: 'entry-'.$entry->id);
        })->map(function ($groups) use ($movementRow) {
            $entries = $groups->pluck('entries')->flatMap(fn ($entries) => $entries)->unique('id')->values();

            return $movementRow(['entries' => $entries, 'net' => round($groups->sum('net'), 2), 'account' => 'Cash receipt']);
        });

        $previous = CashierSession::whereDate('session_date', '<', $date)->latest('session_date')->first();
        $openingSources = $previous ? [['url' => route('cashier.index', ['date' => $previous->session_date->toDateString()]),
            'number' => 'Previous cashier · '.$previous->session_date->format('d M Y')]] : [];
        $opening = ['date' => Carbon::parse($date)->startOfDay(), 'number' => 'Opening cash',
            'description' => 'Physical cash recorded at the start of this date',
            'note' => $session?->notes, 'account' => 'Cashier drawer', 'amount' => $summary['cash_opening'], 'sources' => $openingSources];
        $all = collect([$opening])->concat($ledgerReceipts)
            ->concat($groups->where('kind', 'other')->map($movementRow))->concat($paid);
        if ($summary['separate_cash_balance'] && $summary['drawer_adjustment'] != 0) {
            $all->push(['date' => Carbon::parse($date)->endOfDay(), 'number' => 'Cash count adjustment',
                'description' => 'Recorded cash counts and amounts separated at earlier closings',
                'note' => 'Opening / closing counts remain in cashier history.', 'account' => 'Cashier drawer',
                'amount' => $summary['drawer_adjustment'], 'sources' => [['url' => route('cashier.index', ['date' => $date]), 'number' => 'View cashier history']]]);
        }
        $all = $all->sortBy('date')->values();
        $running = 0;
        $all = $all->map(function ($row) use (&$running) {
            $running += (int) round($row['amount'] * 100);

            return $row + ['running_balance' => $running / 100];
        });

        return ['opening' => collect([$opening]), 'received' => $received->sortBy('date')->values(),
            'paid' => $paid->sortBy('date')->values(), 'balance' => $all, 'washing' => $washing];
    }
}
