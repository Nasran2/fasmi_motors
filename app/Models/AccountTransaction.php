<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AccountTransaction extends Model
{
    public const BALANCE_ADJUSTMENT_TYPES = ['adjustment_increase', 'adjustment_decrease'];

    public function scopeBalanceAdjustments($query, bool $include = true)
    {
        $condition = function ($adjustments) {
            $adjustments->whereIn('transaction_type', self::BALANCE_ADJUSTMENT_TYPES)
                ->orWhere(function ($reversal) {
                    // A reversed correction remains a correction, rather than money received or spent.
                    $reversal->where('transaction_type', 'reversal')
                        ->where('description', 'like', 'Reversal of %')
                        ->whereExists(function ($original) {
                            $original->selectRaw('1')->from('account_transactions as adjustment_original')
                                ->whereIn('adjustment_original.transaction_type', self::BALANCE_ADJUSTMENT_TYPES)
                                ->whereColumn('adjustment_original.account_id', 'account_transactions.account_id')
                                ->whereRaw('adjustment_original.transaction_no = SUBSTR(account_transactions.description, 13)');
                        });
                });
        };

        return $include ? $query->where($condition) : $query->whereNot($condition);
    }

    public function scopeLedger($query, string $type)
    {
        if ($type !== 'cash') {
            return $query->where('account_id', Account::systemAccount($type)?->id);
        }

        $ids = collect(['main', 'spare_parts', 'washing'])->map(fn ($type) => Account::systemAccount($type)?->id)->filter();
        $mainId = Account::systemAccount('main')?->id;

        return $query->whereIn('account_id', $ids)
            ->where(function ($q) use ($mainId) {
                $q->where(function ($mainQ) use ($mainId) {
                    $mainQ->where('account_id', $mainId)
                        ->whereNull('funding_source')
                        ->where(fn ($sub) => $sub->where('credit', '>', 0)->orWhere('debit', '<=', 0));
                })->orWhere(function ($otherQ) use ($mainId) {
                    $otherQ->where('account_id', '!=', $mainId)
                        ->where(fn ($funding) => $funding->whereNull('funding_source')->orWhere('funding_source', 'spare_parts_cashier'));
                });
            })
            ->where(function ($cash) {
                $cash->where('account_type', 'cash')->orWhere(function ($logical) {
                    $logical->whereIn('account_type', ['main', 'spare_parts', 'washing'])->where('payment_method', 'Cash');
                });
            });
    }

    public function getDisplayTimeAttribute(): string
    {
        if ($this->transaction_date && $this->transaction_date->format('H:i:s') !== '00:00:00') {
            return $this->transaction_date->format('h:i A');
        }

        if ($this->created_at) {
            return $this->created_at->format('h:i A');
        }

        return $this->transaction_date ? $this->transaction_date->format('h:i A') : '—';
    }

    public function getDisplayDateTimeAttribute(): string
    {
        $time = $this->display_time;
        $date = $this->transaction_date ? $this->transaction_date->format('d M Y') : '';

        return trim("{$date}, {$time}");
    }

    public function scopePosted($query)
    {
        // Reversed originals remain part of history; their compensating entry cancels them.
        return $query->whereIn('status', ['active', 'reversed']);
    }

    protected $guarded = [];

    protected $casts = [
        'transaction_date' => 'datetime',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'amount' => 'decimal:2',
        'running_balance' => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
