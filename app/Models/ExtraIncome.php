<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExtraIncome extends Model
{
    use ShopScoped, SoftDeletes;

    public const DESTINATIONS = [
        'main' => 'Main Account',
        'spare_parts' => 'Spare Parts Account',
        'spare_parts_cashier' => 'Spare Parts Cashier',
        'bank' => 'Bank Account',
    ];

    public const PAYMENT_METHODS = [
        'Cash',
        'Bank Transfer',
        'Card',
        'QR',
        'Cheque',
    ];

    public const CHEQUE_INITIAL_STATUSES = [
        'Pending' => 'Pending / Hold',
        'Deposited' => 'Deposited',
        'Cleared' => 'Passed',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'income_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    public function getDestinationLabelAttribute(): string
    {
        return self::DESTINATIONS[$this->destination_account] ?? ($this->account?->name ?: $this->destination_account);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class)->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function version(): string
    {
        return hash('sha256', json_encode($this->getAttributes()));
    }
}
