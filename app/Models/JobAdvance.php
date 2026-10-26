<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAdvance extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date', 'send_sms' => 'boolean'];
    }

    public function jobCard(): BelongsTo
    {
        return $this->belongsTo(JobCard::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class)->withTrashed();
    }

    public function getChangeBlockReasonAttribute(): ?string
    {
        if ($this->payment_id || ! in_array($this->status, ['Completed', 'Hold'], true)) {
            return 'This advance is already billed or inactive.';
        }
        if ($this->cheque_id) {
            $cheque = $this->cheque;
            if (! $cheque || $cheque->trashed() || ! in_array($cheque->status, ['Pending', 'Deposited'], true) || $cheque->forwarded_supplier_id || $cheque->payments()->exists() || $cheque->supplierPayments()->exists()) {
                return 'Manage this settled, forwarded or shared cheque in the cheque register.';
            }
        }

        return null;
    }
}
