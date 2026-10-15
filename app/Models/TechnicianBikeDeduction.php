<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianBikeDeduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['start_month' => 'date', 'next_deduction_month' => 'date'];
    }

    public function getRecoverableBalanceAttribute(): float
    {
        $remaining = max(0, round((float) $this->total_amount - (float) $this->deducted_amount, 2));
        if (! $this->invoice_id) {
            return $remaining;
        }

        return $this->invoice && $this->invoice->status !== 'Cancelled'
            ? min($remaining, max(0, (float) $this->invoice->due_amount)) : 0;
    }

    /** @return BelongsTo<Technician, $this> */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    /** @return BelongsTo<Bike, $this> */
    public function bike(): BelongsTo
    {
        return $this->belongsTo(Bike::class);
    }

    /** @return BelongsTo<JobCard, $this> */
    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
