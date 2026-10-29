<?php

namespace App\Models;

use App\Traits\ShopScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianCommission extends Model
{
    use ShopScoped;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['earned_date' => 'date'];
    }

    public function deductions()
    {
        return $this->hasMany(CommissionDeduction::class);
    }

    public function paymentItems()
    {
        return $this->hasMany(CommissionPaymentItem::class);
    }

    public function getSeparateBalanceAttribute(): float
    {
        if ($this->salary_sheet_id !== null || ! in_array($this->status, ['Pending', 'Partially Paid'], true)
            || $this->invoice?->status === 'Cancelled' || $this->jobCard?->status === 'Cancelled') {
            return 0;
        }

        return max(0, round((float) $this->amount - (float) $this->paid_amount - (float) $this->deducted_amount, 2));
    }

    public function getPreviouslyCoveredAttribute(): float
    {
        return $this->salary_sheet_id !== null || ($this->status === 'Paid' && (float) $this->paid_amount === 0.0)
            ? (float) $this->amount : 0.0;
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
