<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\JobAdvance;
use App\Models\Payment;
use Carbon\Carbon;

class CollectionReportService
{
    public function rows(string $from, string $to)
    {
        $ids = collect(['main', 'washing', 'spare_parts', 'bank'])->map(fn ($type) => Account::systemAccount($type)->id);
        $bankId = Account::systemAccount('bank')->id;
        // Only physical cash entries and the physical BANK ledger are selected.
        // The logical non-cash mirror never becomes a second receipt.
        $entries = AccountTransaction::posted()->whereIn('account_id', $ids)
            ->where(fn ($q) => $q->where('account_id', $bankId)->orWhere(fn ($cash) => $cash->where('payment_method', 'Cash')->whereNull('converted_from_method')))
            ->with('reference')->orderBy('transaction_date')->orderBy('id')->get();
        $invoiceRelations = ['customer', 'bike', 'jobCard.intake.bike.customer', 'jobCard.quickBike', 'jobCard.quickCustomer'];
        $entries->loadMorph('reference', [
            Payment::class => array_map(fn ($relation) => 'invoice.'.$relation, $invoiceRelations),
            JobAdvance::class => ['jobCard.intake.bike.customer', 'jobCard.quickBike', 'jobCard.quickCustomer'],
        ]);
        $entries = $entries->filter(fn ($row) => ! ($row->reference instanceof Payment || $row->reference instanceof JobAdvance) || $row->reference->status === 'Completed');
        $groups = $entries->groupBy(function ($row) use ($bankId) {
            $reference = $row->reference;
            $key = $reference instanceof Payment && $reference->receipt_group_id
                ? 'receipt-group|'.$reference->receipt_group_id
                : ($row->reference_id ? $row->reference_type.'|'.$row->reference_id : 'entry-'.$row->id);

            return ($row->account_id === $bankId ? 'bank|' : 'cash|').$key;
        });
        $receiptGroupIds = $entries->map(fn ($row) => $row->reference instanceof Payment ? $row->reference->receipt_group_id : null)->filter()->unique();
        $receiptMembers = Payment::whereIn('receipt_group_id', $receiptGroupIds)->where('status', 'Completed')->whereHas('invoice')
            ->with(array_map(fn ($relation) => 'invoice.'.$relation, $invoiceRelations))->get()->groupBy('receipt_group_id');
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->endOfDay();
        $rows = collect();
        foreach ($groups as $group) {
            $income = $group->first(fn ($row) => in_array($row->transaction_type, ['income', 'payment_received', 'payment'], true));
            if (! $income || $income->transaction_date->lt($start) || $income->transaction_date->gt($end)) {
                continue;
            }
            $reference = $income->reference;
            if (($reference instanceof Payment || $reference instanceof JobAdvance) && $reference->status !== 'Completed') {
                continue;
            }
            $amount = round($group->sum('credit') - $group->sum('debit'), 2);
            if ($amount <= 0) {
                continue;
            }
            $invoice = $reference instanceof Payment ? $reference->invoice : null;
            $members = $reference instanceof Payment && $reference->receipt_group_id
                ? ($receiptMembers[$reference->receipt_group_id] ?? collect([$reference])) : collect($reference instanceof Payment ? [$reference] : []);
            $components = ['service' => 0, 'labor' => 0, 'washing' => 0, 'other' => 0, 'spare_parts' => 0];
            foreach ($members as $member) {
                if (! $member->invoice) {
                    continue;
                }
                foreach (app(AccountService::class)->paymentComponentAllocation($member->invoice, $member) as $type => $cents) {
                    $components[$type] += $cents;
                }
            }
            if ($members->isEmpty()) {
                $components['other'] = (int) round($amount * 100);
            }
            // A grouped receipt can cover several bikes. Resolve each actual job /
            // invoice bike rather than treating a missing invoice customer as walk-in.
            $bikes = $members->map(fn ($member) => ($member->invoice?->jobCard?->bike ?? $member->invoice?->bike)?->number);
            $customers = $members->map(fn ($member) => ($member->invoice?->customer ?? $member->invoice?->jobCard?->customer)?->name);
            if ($reference instanceof JobAdvance) {
                $bikes->push($reference->jobCard?->bike?->number);
                $customers->push($reference->jobCard?->customer?->name);
            }
            $txDate = $income->transaction_date;
            if ($txDate && $txDate->format('H:i:s') === '00:00:00' && $income->created_at) {
                $txDate = $txDate->copy()->setTimeFrom($income->created_at);
            }
            $rows->push([
                'date' => $txDate, 'number' => $reference?->number ?: $income->transaction_no,
                'invoice' => $members->pluck('invoice.number')->filter()->unique()->implode(', ') ?: $invoice?->number, 'payment_ids' => $members->pluck('id')->all(),
                'customer' => $customers->filter()->unique()->implode(', '), 'bikes' => $bikes->filter()->unique()->values()->all(),
                'description' => $income->description, 'method' => $income->payment_method ?: 'Cash',
                'source_transaction_ids' => $group->pluck('id')->all(),
                'channel' => $income->account_id === $bankId ? 'bank' : 'cash', 'amount' => $amount,
                'components' => array_map(fn ($cents) => $cents / 100, $components),
            ]);
        }

        return $rows->sortByDesc('date')->values();
    }
}
