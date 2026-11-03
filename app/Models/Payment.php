<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int|null $cheque_id
 * @property Carbon $paid_at
 */
class Payment extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<TechnicianSalarySheet, $this> */
    public function salarySheet(): BelongsTo
    {
        return $this->belongsTo(TechnicianSalarySheet::class);
    }

    /** @return BelongsTo<TechnicianBikeDeduction, $this> */
    public function bikeDeduction(): BelongsTo
    {
        return $this->belongsTo(TechnicianBikeDeduction::class, 'technician_bike_deduction_id');
    }

    public function getCorrectionBlockReasonAttribute(): ?string
    {
        if (! in_array($this->status, ['Completed', 'Hold'], true)) {
            return 'This receipt is no longer active.';
        }
        if (in_array($this->method, ['Salary Deduction', 'Manual Clearance'], true)) {
            return 'Correct this payment through its originating workflow.';
        }
        if ((float) $this->refunded_amount > 0) {
            return 'This receipt has a refund. Correct it through the return workflow.';
        }
        if (($this->method === 'Cheque' || $this->status === 'Hold') && ! $this->cheque_id) {
            return 'This held or cheque receipt needs review in its originating workflow.';
        }
        if ($this->cheque_id) {
            $cheque = $this->cheque;
            if (! $cheque || $cheque->trashed() || ! in_array($cheque->status, ['Pending', 'Deposited'], true) || $this->status !== 'Hold') {
                return 'A settled cheque must be handled in the cheque register.';
            }
            $sharedOutsideReceipt = $this->receipt_group_id
                ? $cheque->payments()->where(fn ($query) => $query->whereNull('receipt_group_id')->orWhere('receipt_group_id', '!=', $this->receipt_group_id))->exists()
                : $cheque->payments()->count() !== 1;
            if ($cheque->forwarded_supplier_id || $cheque->supplierPayments()->exists() || $sharedOutsideReceipt) {
                return 'A forwarded or shared cheque must be handled in the cheque register.';
            }
        }

        return null;
    }

    /** @return BelongsTo<Cheque, $this> */
    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class)->withTrashed();
    }

    protected function casts(): array
    {
        return ['paid_at' => 'datetime', 'cancelled_at' => 'datetime', 'washing_to_main' => 'boolean', 'opening_due_to_spare_parts' => 'boolean'];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
