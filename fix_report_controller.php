<?php
$file = 'app/Http/Controllers/ReportController.php';
$content = file_get_contents($file);

$replacement = <<<'REPLACEMENT'
        $stats = [
            'total_in' => AccountTransaction::when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('credit'),
            'total_out' => AccountTransaction::when($account, fn ($q) => $q->where('account_id', $account?->id))->whereBetween('transaction_date', [$from, $to])->sum('debit'),
            'balance' => $account?->balance ?? 0,
            'total_due' => \App\Models\Invoice::whereBetween('created_at', [$from, $to])->where('due_amount', '>', 0)
                ->get()
                ->sum(function($inv) {
                    $serviceExpected = max(0, $inv->total - $inv->parts_amount);
                    return max(0, $serviceExpected - $inv->paid_amount);
                }),
        ];
REPLACEMENT;

$content = preg_replace("/\\\$stats = \[\s*'total_in'.*?'balance' => \\\$account\?->balance \?\? 0,\s*\];/s", $replacement, $content);
file_put_contents($file, $content);
echo "ReportController fixed!";
