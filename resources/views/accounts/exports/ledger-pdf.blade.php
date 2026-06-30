<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .subtitle {
            font-size: 14px;
            color: #666;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }
        .badge {
            padding: 2px 5px;
            border-radius: 3px;
            font-size: 10px;
            text-transform: uppercase;
        }
        .bg-green { background-color: #d1fae5; color: #065f46; }
        .bg-red { background-color: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">Fasmir Motors</div>
        <div class="subtitle">{{ $title }}</div>
        @if($from || $to)
            <div class="subtitle">
                Period: {{ $from ? \Carbon\Carbon::parse($from)->format('d/m/Y') : 'Start' }} to {{ $to ? \Carbon\Carbon::parse($to)->format('d/m/Y') : 'End' }}
            </div>
        @endif
        <div class="subtitle">Generated on: {{ now()->format('d/m/Y h:i A') }} by {{ $printedBy?->name ?? 'System' }}</div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Type</th>
                <th>Method</th>
                <th>Description</th>
                <th>Reference</th>
                <th class="text-right">Debit (Dr)</th>
                <th class="text-right">Credit (Cr)</th>
                <th class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @forelse($transactions as $t)
                <tr>
                    <td>{{ $t->transaction_date->format('d/m/Y H:i A') }}</td>
                    <td>
                        <span class="badge {{ $t->credit > 0 ? 'bg-green' : 'bg-red' }}">
                            {{ ucfirst(str_replace('_', ' ', $t->transaction_type)) }}
                        </span>
                    </td>
                    <td>{{ $t->payment_method ?? '-' }}</td>
                    <td>{{ $t->description }}</td>
                    <td>
                        @if($t->reference_type)
                            {{ class_basename($t->reference_type) }} #{{ $t->reference_id }}
                        @else
                            -
                        @endif
                    </td>
                    <td class="text-right">{{ $t->debit > 0 ? number_format($t->debit, 2) : '-' }}</td>
                    <td class="text-right">{{ $t->credit > 0 ? number_format($t->credit, 2) : '-' }}</td>
                    <td class="text-right">{{ number_format($t->running_balance, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center">No transactions found for the selected period.</td>
                </tr>
            @endforelse
        </tbody>
        <tfoot><tr><th colspan="5">Opening: Rs. {{ number_format($totals['opening'],2) }}</th><th class="text-right">{{ number_format($totals['debit'],2) }}</th><th class="text-right">{{ number_format($totals['credit'],2) }}</th><th class="text-right">{{ number_format($totals['closing'],2) }}</th></tr></tfoot>
    </table>
</body>
</html>
